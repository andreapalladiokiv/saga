# techork/saga

Saga pattern for PHP, built on [Symfony Workflow](https://symfony.com/doc/current/components/workflow.html). The core is framework-agnostic; a Laravel integration ships alongside it.

A saga is a Petri net. Each transition is one queued step. When a step fails past its retries, the runner walks the applied transitions backwards and dispatches a compensation for each.

```
composer require techork/saga
```

Requires PHP 8.2+ and `symfony/workflow` ^7.0 or ^8.0.

---

## The wiring contract

This is the part that will bite you if you skip it. Four invariants are load-bearing, and getting them wrong used to fail silently — the runner now rejects each one loudly, but you still need to know what it expects.

### 1. The Workflow's `$name` MUST be the saga's FQCN

Symfony's `Workflow` takes `$name` as its **fourth, optional** argument, defaulting to `'unnamed'`. Every event name your listeners bind to is derived from it. Omit it and none of your actions or guards ever run.

```php
$registry->addWorkflow(
    new Workflow($definition, $markingStore, $dispatcher, OrderSaga::class),  // ← required
    new InstanceOfSupportStrategy(OrderSubject::class),
);
```

### 2. One `SagaMarkingStore` instance, shared

The runner writes the marking into its injected store and reads it back after `apply()`; the `Workflow` must hold the **same object**. The container binds it as a singleton, so injecting it is correct by default — constructing a fresh `new SagaMarkingStore()` for the `Workflow` is not, and the saga would freeze at its initial place.

### 3. One saga per subject class, or disambiguate by name

Workflows are resolved by subject class **and** saga FQCN. Two sagas over the same subject DTO are fine as long as each `Workflow` carries its own saga's name.

### 4. Transition actions must be idempotent

No queue offers exactly-once delivery, and the runner calls your action before it persists. A worker killed between those two points means the step runs again. The saga lock stops two workers from running a saga *concurrently*; it cannot stop a step from being *retried*.

---

## Defining a saga

```php
final class OrderSaga implements Saga
{
    public function definition(): Definition
    {
        return new Definition(
            ['placed', 'reserved', 'charged', 'shipped'],
            [
                new Transition('reserve_stock', 'placed', 'reserved'),
                new Transition('charge_card', 'reserved', 'charged'),
                new Transition('ship', 'charged', 'shipped'),
            ],
            ['placed'],
        );
    }
}
```

Behaviour attaches through events:

| Event name | Purpose |
| --- | --- |
| `workflow.<FQCN>.guard.<transition>` | Block a transition (`$event->setBlocked(true)`) |
| `workflow.<FQCN>.transition.<transition>` | The action — runs during `apply()` |
| `saga.<FQCN>.compensate.<transition>` | Undo that transition |

Compensation listeners receive a `CompensateEvent` carrying the subject, the triggering `$cause`, and `$failed` — true for the step that threw. **That step is compensated first, and it may have done nothing at all** (an action can throw on its first line), so compensations must tolerate being called for work that never happened.

Transition names must be unique within a definition. Symfony fires the full event cycle once per matching arc, while compensation keys on names, so two arcs named the same would run the action twice and undo it once. `start()` rejects it.

### Retry policy

Optional, by convention, mirroring Laravel jobs:

```php
public function tries(string $transition): int { return $transition === 'charge_card' ? 3 : 1; }
public function backoff(string $transition): array { return [10, 60, 300]; }
```

The default is one attempt: the library cannot know whether your action is idempotent, and re-running a non-idempotent step is worse than compensating. Lock conflicts are handled on a **separate** budget and never reach compensation.

### Routing

```php
final class ShippingSaga implements Saga, SagaRouting
{
    public function connection(): ?string { return 'redis-long'; }
    public function queue(): ?string { return 'shipping'; }
}
```

---

## Running one

```php
$runner->start(new OrderSaga, $order->id, new OrderSubject($order->id));
```

That persists the state and queues the first step. Everything after that is queue-driven. A saga whose first transition is guard-blocked starts parked in a wait state — `start()` only rejects an initial marking with no outgoing transitions at all, which is a definition bug.

### Waiting, and signalling

A wait is an edge in the graph. `Signal` is a `Transition` the runner never fires by itself, and that one rule is the whole mechanism:

```php
new Definition(
    ['created', 'awaiting_payment', 'captured', 'settled', 'expired'],
    [
        new Transition('publish', 'created', 'awaiting_payment'),
        new Signal('payment_received', 'awaiting_payment', 'captured', awaits: PaymentReceived::class),
        new Transition('expire', 'awaiting_payment', 'expired'),
        new Transition('settle', 'captured', 'settled'),
    ],
    ['created'],
);
```

Because it extends `Transition`, Symfony's own dumpers render it — the wait shows up in your Graphviz/PlantUML/Mermaid diagram like any other edge, and its guards are the ordinary `workflow.<FQCN>.guard.<name>` listeners. A signals list declared beside the definition would be invisible to both.

Three states follow, all derived from the definition and the marking with nothing extra persisted:

| State | How it is determined |
| --- | --- |
| moving | something enabled is not a `Signal` — that is what gets queued |
| **parked** | everything enabled is a `Signal` — nothing is queued, the saga waits |
| **stalled** | nothing is enabled at all — a real anomaly, not the normal shape of waiting |

**Firing it.** Only from outside, with a payload:

```php
$outcome = $runner->signal($saga, $link->id, new PaymentReceived(
    card: $request->cardMask(), address: $request->billingAddress(),
));
```

Of the Signals currently enabled, exactly one must `accepts()` the payload. Zero raises an error naming what the saga is actually waiting for; more than one is an ambiguous definition. `run()` on a Signal is refused outright — it would advance the saga past its own wait with no data. `SignalOutcome` is `Applied` or `NotFound`; a late signal for a finished saga is not an error.

**Handling it.** A signal's listener is an ordinary transition listener. The only difference is that the payload is in the apply context:

```php
Event::listen('workflow.'.CheckoutSaga::class.'.transition.payment_received',
    function (TransitionEvent $event): void {
        $payment = Signal::payload($event, PaymentReceived::class);   // typed and checked

        $subject = $event->getSubject();
        $subject->card = $payment->card;        // ← this is what makes it durable
    });
```

`Signal::payload()` exists so you do not index `array<mixed>` by a string key: it narrows the type for static analysis and fails with a clear message if the transition was fired by `run()`, or signalled with something else.

**The payload does not travel past its own apply.** Symfony's context is per-apply and is never persisted. Every event of that apply can read it — `transition`, `leave`, `enter`, `entered`, `completed`, and `announce.<next>`, which is useful if you only need the data to decide something about the next step. But the next transition runs later, on a worker, with an empty context. **So the listener must fold whatever has to survive into the subject** — which also means the subject has to be mutable, and cannot be replaced.

**A mixed exit needs no extra concept, but its ordinary transition must be guarded.** `expire` leaves the same place and is not a `Signal`, so the runner queues it — and if nothing blocks it, it fires immediately and the saga never parks at all. Guard it on the deadline, and wake it with `requeue($saga, $sagaId)` from a scheduled sweep.

---

## Laravel setup

Auto-discovered. Publish the migration:

```
php artisan vendor:publish --tag=saga-migrations
php artisan migrate
```

Register your workflows against the container's `Registry` in a provider's `boot()`.

### Schema

One table, six columns: `id`, `marking`, `subject`, `history`, `version`, timestamps — the original shape, unchanged.

`subject` is an ordinary `longText`, and that is deliberate. Raw `serialize()` output could not live there: it encodes non-public property names with NUL delimiters (`\0*\0cents`), which PostgreSQL silently truncates at, MySQL rejects in strict mode, and MariaDB substitutes bytes in. Text-safety is the **codec's** job instead of the column's — both shipped codecs return base64 — which keeps the schema on MySQL's 4 GiB `LONGTEXT` rather than `BLOB`'s 64 KiB. That ceiling matters, because encrypting inflates the payload about 3.3×.

Nothing else is stored. In particular the row does not record which saga it belongs to, which steps are in flight, or whether a rollback failed — so there is no way to enumerate stuck sagas or resume them programmatically. That is a deliberate scope choice; see *Known gaps*.

### The subject is authenticated, not just stored

`unserialize()` does not parse data — it **constructs** the class the payload names, assigns its properties and runs its magic methods. So whoever can write the `subject` column chooses which already-loaded class gets built inside your queue worker, and its `__wakeup`/`__destruct` then runs. The precondition is write access to the row: a SQL injection anywhere else in the application, a restored backup, a replica, a BI tool.

Checking the result is too late — `$value instanceof OrderSubject` runs after the object exists, and the failed check is itself what drops it and fires `__destruct`. So the guard has to act **before** `unserialize()`.

`EncryptedSubjectCodec`, wired by default, does that with Laravel's encrypter. Every cipher it supports is authenticated (HMAC-SHA256 over `iv.value` for CBC, an AEAD tag for GCM), so a payload the application did not write fails to decrypt and never reaches `unserialize()`. Two consequences:

- No `allowed_classes` allow-list is needed, and that matters practically: an allow-list matches **one** exact class name, so it turns every nested object into `__PHP_Incomplete_Class` — and assigning that to a typed property is a raw `TypeError`. `class Order { public Money $total; }` simply could not be stored. Subclasses were refused for the same reason.
- The column stops being readable. Backups, replicas and BI exports no longer expose what the subject carries, and a database error that quotes its bindings quotes ciphertext.

**The key must not live in the database it protects.** Laravel's `APP_KEY` is the right source; rotation works via `previous_keys`.

For a store that never leaves the process, `PlainSubjectCodec` is correct and is what `InMemorySagaStateRepository` uses — there is nothing to forge.

**The default cache store must support atomic locks** — `redis`, `memcached`, `dynamodb`, `database` or `array`. The `file` store does not, and the provider refuses to build the lock rather than run without mutual exclusion. Two workers on one saga would each load the subject, mutate it, and write it back whole, silently losing one another's changes.

### Concurrency, and what it costs

Every step of one saga runs inside a lock keyed on the saga id, held for the whole step including your action. That is why the lock is a cache lock and not a database transaction — holding an open transaction across a call to a payment gateway would tie up a connection for as long as the call takes.

The consequence is that **the branches of a fork are interleaved, not overlapped**. A three-way fork of two-second branches takes six seconds, not two. This follows from the subject being one mutable object persisted whole; token movements commute, arbitrary DTO mutations do not.

Two durations answer different questions, and they are coupled:

- `CacheSagaLock::$ttlSeconds` (default 120) — how long the lock outlives a dead holder. **Must exceed your slowest transition.**
- `SagaStepJob`'s retry window — 12 rounds × (3s wait + 15s delay) = 216s. **Must exceed the TTL**, or a step gives up before a dead holder's lock expires and the saga is stranded.

---

## Known gaps

**A wide fork wastes jobs.** Every completing branch re-queues its still-pending siblings. Measured on a two-branch fork of L steps per branch, that settles at a flat ~1.9 pushes per real transition with a constant queue depth — linear, not quadratic. The duplicates are harmless: a duplicate takes the saga lock, finds its transition already consumed by the `can()` check, and returns. Eliminating them entirely would need the dispatched set persisted on the row.

**A failed rollback parks the saga, and unparking it is manual.** `compensateAndDelete()` does not delete when a compensation throws — the row holds the subject and history needed to retry it — and appends `SagaRunner::ROLLBACK_FAILED` to `history` so nothing advances it afterwards. `run()` and `resume()` both refuse. The signal is `SagaFailedException`, logged at `critical` and rethrown by the job; there is no query to list such rows.

**Definition drift is reported, not handled.** Renaming a place or a transition raises `SagaDefinitionDriftException`, and the job declines to compensate on it — rolling back is the wrong and irreversible response to a code change, since one renamed place would mean a refund for every saga parked in it. It is logged at `critical` and lands in `failed_jobs`; deciding what to do is manual, and there is no query to list the affected sagas.

**There is no way to enumerate stuck sagas.** Recovery exists — `$runner->resume($saga, $sagaId)` re-queues whatever the saga can currently fire, which is the fix for a hand-off lost between the save and the push — but finding the ids to call it with is on you. Doing it automatically on every replayed job was tried and reverted: it turned each duplicate into another push and made a two-branch fork grow 2^L.

### Deploys

Sagas outlive them. Renaming a place, or a transition, raises `SagaDefinitionDriftException` — reported rather than compensated, because rolling back is the wrong and irreversible response to a code change. Park those sagas and decide by hand.

Subject DTOs: add properties with defaults, never rename or move the class. Deserialization is restricted to `Saga::subjectClass()`, so a moved class is refused rather than silently materialised as a ghost object.

---

## Exceptions

| Type | Meaning |
| --- | --- |
| `SagaException` | Base. Operational problems the library detects itself |
| `SagaConcurrencyException` | Another worker holds or advanced the saga. **Retry; never compensate** |
| `SagaDefinitionDriftException` | The code no longer fits this saga — a renamed place or transition, or a transition that became a `Signal`. **Never compensated**: rolling back is an irreversible response to a deploy. Logged at `critical` and left where it is |
| `SagaAlreadyExistsException` | `start()` called twice for one id — treat as "already running" |
| `SagaFailedException` | A rollback failed and is incomplete. Carries `$cause` and `$compensationErrors` |

A failure inside your own transition action is not wrapped: it bubbles out of `run()` as whatever you threw.

A missing saga row is **not** an error — `run()` and `compensateAndDelete()` return quietly for an id that no longer exists, because signal-driven callers may legitimately race a saga that has just finished.

---

## Development

```
composer test      # phpunit
composer analyse   # phpstan: src at level 8, tests at 5
composer ci        # both
```

CI runs a matrix over PHP 8.2–8.5 × Symfony Workflow 7/8 × Laravel 11/12/13, with `--prefer-lowest` on the minimum lane, and lints every file on the target PHP — polyfills cannot fix syntax, and only a real parse on the minimum version catches a typed class constant slipping in.

## License

MIT. See [LICENSE](LICENSE).
