<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console;

use ParseError;
use PHPUnit\Framework\TestCase;
use Techork\Saga\Laravel\Console\BlueprintIssue;
use Techork\Saga\Laravel\Console\SagaBlueprint;
use Techork\Saga\Laravel\Console\SagaScaffolder;
use Techork\Saga\Laravel\Console\ScaffoldingPlan;
use Techork\Saga\Laravel\Console\SagaStep;
use Techork\Saga\Laravel\Console\SagaStepKind;
use Techork\Saga\Tests\Checkout\PaymentReceived;
use Techork\Saga\Tests\Laravel\Console\Fixtures\AbstractChildSaga;
use Techork\Saga\Tests\Laravel\Console\Fixtures\AwaitablePayload;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ChildSagaContract;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ChildSubject;
use Techork\Saga\Tests\Laravel\Console\Fixtures\NeedsArgsChildSaga;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ParameterlessChildSaga;

use function array_filter;
use function array_map;
use function array_splice;
use function array_values;
use function implode;
use function is_array;
use function sprintf;
use function str_contains;
use function strlen;
use function strpos;
use function str_replace;
use function substr_count;
use function token_get_all;

/**
 * The generator writes files people keep working in, so the two things that
 * matter most here are not what it writes but what it does NOT: it never
 * touches a line between the markers, and it never rewrites a `definition()`
 * someone edited by hand. Each of those is pinned by running the generator a
 * second time over its own output — which is also the only way the promise
 * "re-run it to carry on" can be checked at all.
 *
 * The generated sources are parsed, not executed. Parsing is the honest check
 * here because every file this test writes lands in `App\Sagas`, a namespace
 * that exists only in the blueprint — requiring one would be testing the
 * fixture, not the generator. That a generated saga actually RUNS is a separate
 * test, over a graph the wizard assembled end to end.
 */
final class SagaScaffolderTest extends TestCase
{
    private const SAGA = 'OrderSaga.php';

    private const LISTENERS = 'OrderSagaListeners.php';

    private const SUBJECT = 'OrderSubject.php';

    private function scaffolder(): SagaScaffolder
    {
        return new SagaScaffolder();
    }

    /**
     * A saga with a start, a terminal place, and nothing to complain about.
     *
     * The subject is a parameter because one test needs a graph that is sound
     * everywhere except there, and a plan that is blocked for another reason
     * would say nothing about the reason under test.
     */
    private function ordered(string $subject = 'App\Sagas\OrderSubject'): SagaBlueprint
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', $subject);

        $blueprint->addInitial('placed');
        $blueprint->addPlace('reserved');
        $blueprint->addPlace('charged');

        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['reserved'], ['charged']));

        return $blueprint;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function plan(SagaBlueprint $blueprint, array $files = [], bool $force = false): ScaffoldingPlan
    {
        return $this->scaffolder()->plan($blueprint, $files, $force);
    }

    /**
     * The plan's files as the directory they would become — what a second run
     * reads, and what the "nothing changed" tests compare.
     *
     * @return array<string, string>
     */
    private static function files(ScaffoldingPlan $plan): array
    {
        $files = [];

        foreach ($plan->files as $file) {
            $files[$file->name] = $file->contents;
        }

        return $files;
    }

    /**
     * Every file in the plan under one name — one, unless something has gone
     * wrong, because a plan is written in order and the last one wins.
     *
     * @return list<\Techork\Saga\Laravel\Console\GeneratedFile>
     */
    private static function named(ScaffoldingPlan $plan, string $name): array
    {
        return array_values(array_filter(
            $plan->files,
            static fn ($file): bool => $file->name === $name,
        ));
    }

    /**
     * The text between the definition markers, markers included — what the
     * generator hashes, and what it hands back as the diff.
     */
    private static function region(string $source): string
    {
        $start = strpos($source, SagaScaffolder::DEFINITION_START);
        $end = strpos($source, SagaScaffolder::DEFINITION_END);

        if ($start === false || $end === false) {
            self::fail("This file has no definition region:\n\n" . $source);
        }

        return substr($source, $start, $end - $start + strlen(SagaScaffolder::DEFINITION_END));
    }

    /**
     * A generated file with its region swapped for another, which is how a
     * method edited by hand is built without typing one out.
     */
    private static function withRegion(string $source, string $region): string
    {
        $replaced = str_replace(self::region($source), $region, $source);

        if ($replaced === $source) {
            self::fail('The region was not replaced, so this would test nothing.');
        }

        return $replaced;
    }

    /**
     * The same, for the blueprint block: the graph the author says they meant,
     * stamped with the hash the last write left — which is the only hash a file
     * can carry until a write succeeds, and the whole trap being tested.
     */
    private static function withBlueprint(string $source, SagaBlueprint $blueprint): string
    {
        $lines = explode("\n", $source);
        $start = null;
        $end = null;

        foreach ($lines as $index => $line) {
            if ($start === null && str_contains($line, SagaBlueprint::DOCBLOCK_TAG)) {
                $start = $index;
            } elseif ($start !== null && str_contains($line, SagaBlueprint::DOCBLOCK_END)) {
                $end = $index;

                break;
            }
        }

        if ($start === null || $end === null) {
            self::fail("This file has no blueprint block to replace:\n\n" . $source);
        }

        array_splice($lines, $start, $end - $start + 1, $blueprint->toDocblockLines());

        return implode("\n", $lines);
    }

    private static function file(ScaffoldingPlan $plan, string $name): string
    {
        $file = $plan->file($name);

        if ($file === null) {
            self::fail(sprintf(
                'No generated file named %s. There were: %s.',
                $name,
                implode(', ', array_map(static fn ($each): string => $each->name, $plan->files)) ?: '(none)',
            ));
        }

        return $file->contents;
    }

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    private static function text(array $issues): string
    {
        return implode("\n", array_map(
            static fn (BlueprintIssue $issue): string => $issue->message,
            $issues,
        ));
    }

    private static function assertReports(ScaffoldingPlan $plan, string $needle, bool $error): void
    {
        $found = array_filter(
            $plan->issues,
            static fn (BlueprintIssue $issue): bool => $issue->isError === $error
                && str_contains($issue->message, $needle),
        );

        self::assertNotEmpty($found, sprintf(
            'No %s mentioning "%s". The reported issues were: %s',
            $error ? 'error' : 'warning',
            $needle,
            self::text($plan->issues) ?: '(none)',
        ));
    }

    /**
     * A generated file has to be valid PHP before anything else can be true of
     * it, and `token_get_all` with the parser flag is the cheapest honest way to
     * say so: it runs the real grammar and throws on a syntax error, in this
     * process, without a subprocess or a temporary file.
     *
     * The tokens are then read rather than discarded, because parsing says the
     * file is well-formed and nothing more — a template whose placeholders were
     * never filled tokenizes perfectly well up to the point where it declares
     * nothing at all.
     */
    private static function assertParses(ScaffoldingPlan $plan, string $name): void
    {
        $source = self::file($plan, $name);

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $e) {
            self::fail(sprintf("%s is not valid PHP: %s\n\n%s", $name, $e->getMessage(), $source));
        }

        $declarations = array_filter(
            $tokens,
            static fn ($token): bool => is_array($token) && $token[0] === T_CLASS,
        );

        self::assertNotEmpty($declarations, sprintf('%s declares no class at all.', $name));
        self::assertStringStartsWith('<?php', $source);
    }

    /**
     * Count a stub by its whole marker line.
     *
     * The trailing newline is load-bearing: `charge` and `charge_card` are both
     * legal step names, and a count without it would find the first inside the
     * second.
     */
    private static function countStub(string $source, string $kind, string $step): int
    {
        return substr_count($source, sprintf("// @saga:stub:%s:%s\n", $kind, $step));
    }

    // ---------------------------------------------------------------- a fresh plan

    public function test_a_fresh_plan_writes_the_class_the_listeners_and_the_subject(): void
    {
        $plan = $this->plan($this->ordered());

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertSame([self::SAGA, self::LISTENERS, self::SUBJECT], array_map(
            static fn ($file): string => $file->name,
            $plan->files,
        ));

        self::assertParses($plan, self::SAGA);
        self::assertParses($plan, self::LISTENERS);
        self::assertParses($plan, self::SUBJECT);
    }

    public function test_the_definition_is_rendered_as_literals_from_the_graph(): void
    {
        $saga = self::file($this->plan($this->ordered()), self::SAGA);

        self::assertStringContainsString(implode("\n", [
            '    // @saga:generated:definition:start',
            '    public function definition(): Definition',
            '    {',
            '        return new Definition(',
            "            ['placed', 'reserved', 'charged'],",
            '            [',
            "                new Transition('reserve_stock', 'placed', 'reserved'),",
            "                new Transition('charge', 'reserved', 'charged'),",
            '            ],',
            "            'placed',",
            '        );',
            '    }',
            '    // @saga:generated:definition:end',
        ]), $saga);
    }

    public function test_a_single_place_is_written_as_a_string_and_several_as_an_array(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addPlace('charged');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['placed'], ['charged']));

        $saga = self::file($this->plan($blueprint), self::SAGA);

        // A fork or a join is a list, and a single place is not: the graph is
        // what is being read, and a one-element array reads like a mistake.
        self::assertStringContainsString("new Transition('charge', 'placed', 'charged'),", $saga);
        self::assertStringContainsString("            ['placed', 'charged'],", $saga);
        self::assertStringContainsString("            'placed',\n", $saga);
    }

    public function test_the_file_carries_back_the_blueprint_that_produced_it(): void
    {
        $blueprint = $this->ordered();
        $plan = $this->plan($blueprint);

        $recovered = SagaBlueprint::fromSource(self::file($plan, self::SAGA));

        self::assertNotNull($recovered);
        // Including the stamp: the renderer version and the hash of the body it
        // wrote. That pair is the only reason a later run can tell its own
        // output from a hand edit.
        self::assertSame($blueprint->toJson(), $recovered->toJson());
    }

    public function test_the_class_imports_what_the_definition_names(): void
    {
        $saga = self::file($this->plan($this->ordered()), self::SAGA);

        self::assertStringContainsString(implode("\n", [
            'use Symfony\Component\Workflow\Definition;',
            'use Symfony\Component\Workflow\Transition;',
            'use Techork\Saga\Saga;',
        ]), $saga);

        // The subject lives in the saga's own namespace, so it is not imported
        // — there is nothing to import it from.
        self::assertStringNotContainsString('use App\Sagas\OrderSubject;', $saga);
    }

    public function test_the_listeners_import_only_what_their_stubs_use(): void
    {
        $listeners = self::file($this->plan($this->ordered()), self::LISTENERS);

        self::assertStringContainsString(implode("\n", [
            'use Illuminate\Contracts\Events\Dispatcher;',
            'use Symfony\Component\Workflow\Event\TransitionEvent;',
            'use Techork\Saga\Event\CompensateEvent;',
        ]), $listeners);

        // Nothing here waits, and nothing here needs a guard, so the classes a
        // wait would have needed are absent rather than merely unused.
        self::assertStringNotContainsString('use Techork\Saga\Signal;', $listeners);
        self::assertStringNotContainsString('GuardEvent', $listeners);
    }

    public function test_every_step_gets_an_action_and_a_compensate_stub_in_order(): void
    {
        $listeners = self::file($this->plan($this->ordered()), self::LISTENERS);

        self::assertStringContainsString(implode("\n", [
            '            // @saga:stub:action:reserve_stock',
            "            'workflow.'.OrderSaga::class.'.transition.reserve_stock' => static function (TransitionEvent \$event): void {",
            '                // @todo apply the step, and fold what it changed into the subject',
            '            },',
            '',
            '            // @saga:stub:compensate:reserve_stock',
            "            'saga.'.OrderSaga::class.'.compensate.reserve_stock' => static function (CompensateEvent \$event): void {",
            '                // @todo undo the step. Its subject is on the event: $event->subject',
            '            },',
        ]), $listeners);

        // Steps in the order the graph has them, and the closing marker last.
        self::assertLessThan(
            strpos($listeners, '@saga:stub:action:charge'),
            strpos($listeners, '@saga:stub:action:reserve_stock'),
        );
        self::assertStringContainsString("            // @saga:listeners:end\n", $listeners);
    }

    // ---------------------------------------------------------------- running it twice

    public function test_planning_twice_over_the_same_graph_changes_nothing(): void
    {
        $blueprint = $this->ordered();
        $first = $this->plan($blueprint);

        // The second run reads what the first one wrote, which is the only
        // version of "idempotent" that means anything to the person re-running
        // the command to add one more step.
        $second = $this->plan($blueprint, self::files($first));

        self::assertSame(self::files($first), self::files($second));
        self::assertFalse($second->isBlocked(), self::text($second->issues));
    }

    public function test_an_edited_action_body_survives_a_later_run_and_the_new_step_arrives(): void
    {
        $blueprint = $this->ordered();
        $first = $this->plan($blueprint);
        $files = self::files($first);

        $implemented = str_replace(
            '// @todo apply the step, and fold what it changed into the subject',
            '$this->stock->reserve($event->getSubject());',
            $files[self::LISTENERS],
        );

        self::assertNotSame($files[self::LISTENERS], $implemented, 'the edit did not land');
        $files[self::LISTENERS] = $implemented;

        // Carry on building: one more step, on top of everything above.
        $blueprint->addPlace('shipped');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'ship', ['charged'], ['shipped']));

        $second = $this->plan($blueprint, $files);
        $listeners = self::file($second, self::LISTENERS);

        self::assertFalse($second->isBlocked(), self::text($second->issues));

        // The author's body is untouched, and the stub it lives in is not
        // duplicated or reordered around it. Both steps' action stubs carried
        // the same @todo, so the edit landed in both and only the new step's is
        // still the untouched one.
        self::assertSame(2, substr_count($listeners, '$this->stock->reserve($event->getSubject());'));
        self::assertSame(1, substr_count($listeners, '// @todo apply the step, and fold what it changed into the subject'));
        self::assertSame(1, self::countStub($listeners, 'action', 'reserve_stock'));
        self::assertSame(1, self::countStub($listeners, 'compensate', 'reserve_stock'));

        // The new step arrived, once, appended after the ones already there.
        self::assertSame(1, self::countStub($listeners, 'action', 'ship'));
        self::assertSame(1, self::countStub($listeners, 'compensate', 'ship'));
        self::assertLessThan(
            strpos($listeners, '// @saga:stub:action:ship'),
            strpos($listeners, '// @saga:stub:action:charge'),
        );

        // And the definition was rewritten from the graph, markers intact.
        $saga = self::file($second, self::SAGA);
        self::assertStringContainsString("new Transition('ship', 'charged', 'shipped'),", $saga);
        self::assertSame(1, substr_count($saga, '// @saga:generated:definition:start'));
    }

    public function test_a_step_whose_name_is_a_prefix_of_another_gets_its_own_stub(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addPlace('reserved');
        $blueprint->addPlace('charged');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge_card', ['placed'], ['reserved']));

        $files = self::files($this->plan($blueprint));

        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['reserved'], ['paid']));

        $listeners = self::file($this->plan($blueprint, $files), self::LISTENERS);

        self::assertSame(1, self::countStub($listeners, 'action', 'charge'));
        self::assertSame(1, self::countStub($listeners, 'action', 'charge_card'));
    }

    public function test_a_step_the_graph_no_longer_has_is_reported_rather_than_removed(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $without = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $without->addInitial('placed');
        $without->addPlace('reserved');
        $without->addPlace('charged');
        $without->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']));

        $plan = $this->plan($without, $files);

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertReports($plan, '@saga:stub:action:charge', false);
        self::assertReports($plan, '@saga:stub:compensate:charge', false);

        // Reported, never deleted: the body inside a stub is the author's work,
        // and nothing here can tell a step someone forgot from a listener that
        // was never about a step at all.
        self::assertSame(1, self::countStub(self::file($plan, self::LISTENERS), 'action', 'charge'));
    }

    // ---------------------------------------------------------------- refusing

    public function test_a_hand_edited_definition_is_refused_with_a_diff(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace(
            "new Transition('reserve_stock', 'placed', 'reserved'),",
            "new Transition('reserve_stock', 'placed', 'booked'),",
            $files[self::SAGA],
        );

        $plan = $this->plan($blueprint, $files);

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, '--force', true);
        self::assertReports($plan, "the blueprint says: new Transition('reserve_stock', 'placed', 'reserved'),", true);
        self::assertReports($plan, "the file says:      new Transition('reserve_stock', 'placed', 'booked'),", true);
    }

    public function test_force_overwrites_the_hand_edited_definition_and_says_so(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace("'placed', 'reserved'),", "'placed', 'booked'),", $files[self::SAGA]);

        $plan = $this->plan($blueprint, $files, true);

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertReports($plan, '--force overwrote that edit', false);
        self::assertStringContainsString("'placed', 'reserved'),", self::file($plan, self::SAGA));
    }

    public function test_a_blueprint_that_cannot_be_read_back_blocks_the_write_instead_of_crashing(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace(' * @saga-blueprint:end', '', $files[self::SAGA]);

        $plan = $this->plan($blueprint, $files);

        // "I cannot read what is there" and "there is nothing to carry forward"
        // are different answers, and only the second is a licence to write.
        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'cannot be read back', true);
    }

    public function test_a_marker_deleted_by_hand_blocks_the_write(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace(
            "    // @saga:generated:helpers:begin\n",
            '',
            $files[self::SAGA],
        );

        $plan = $this->plan($blueprint, $files);

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'helpers:begin', true);
        self::assertReports($plan, 'Put the marker back', true);
    }

    // ---------------------------------------------------------------- guards

    public function test_a_guard_is_generated_only_for_an_ordinary_transition_leaving_a_parking_place(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        // `charged` is now left by both a Signal and an ordinary transition:
        // the transition is queued the moment the saga arrives, so the guard is
        // the only thing that can hold the saga there at all.
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], PaymentReceived::class));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'expire', ['charged'], ['placed']));

        $plan = $this->plan($blueprint);
        $listeners = self::file($plan, self::LISTENERS);

        self::assertSame(1, self::countStub($listeners, 'guard', 'expire'));
        self::assertStringContainsString("'workflow.'.OrderSaga::class.'.guard.expire' => static function (GuardEvent \$event): void {", $listeners);
        self::assertStringContainsString('use Symfony\Component\Workflow\Event\GuardEvent;', $listeners);

        // A plain transition out of a place nothing waits in is queued and that
        // is the whole point of it — a guard there would be a bug.
        self::assertSame(0, self::countStub($listeners, 'guard', 'reserve_stock'));
        self::assertSame(0, self::countStub($listeners, 'guard', 'charge'));
    }

    public function test_a_signal_action_reads_its_payload_through_the_awaited_type(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], PaymentReceived::class));

        $listeners = self::file($this->plan($blueprint), self::LISTENERS);

        self::assertStringContainsString(implode("\n", [
            '            // @saga:stub:action:pay',
            "            'workflow.'.OrderSaga::class.'.transition.pay' => static function (TransitionEvent \$event): void {",
            '                $payload = Signal::payload($event, PaymentReceived::class);',
            '',
            '                // @todo fold $payload into the subject — a payload never survives its own apply',
            '            },',
        ]), $listeners);

        self::assertStringContainsString('use Techork\Saga\Tests\Checkout\PaymentReceived;', $listeners);
        self::assertStringContainsString('use Techork\Saga\Signal;', $listeners);
    }

    // ---------------------------------------------------------------- signals and payloads

    public function test_a_signal_awaiting_a_class_that_is_not_there_yet_gets_a_payload_dto(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], 'App\Sagas\PaymentReceived'));

        $plan = $this->plan($blueprint);

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertStringContainsString('namespace App\Sagas;', self::file($plan, 'PaymentReceived.php'));
        self::assertStringContainsString('final class PaymentReceived', self::file($plan, 'PaymentReceived.php'));
        self::assertParses($plan, 'PaymentReceived.php');
    }

    public function test_a_signal_awaiting_a_class_in_another_namespace_is_refused(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], 'Other\Ns\PaymentReceived'));

        $plan = $this->plan($blueprint);

        // The generator writes next to the saga, so a class named for another
        // namespace is one it can neither write nor trust to exist.
        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'is not in App\Sagas', true);
        self::assertNull($plan->file('PaymentReceived.php'));
    }

    public function test_a_name_written_with_a_leading_backslash_is_the_same_name(): void
    {
        // `\App\Sagas\OrderSaga` and `App\Sagas\OrderSaga` are one class, and a
        // blueprint read back from a file someone edited by hand can carry
        // either — the pattern a class name is checked against allows the
        // backslash, as PHP does. Nothing here may read it as a foreign
        // namespace: the namespace it would render is `namespace \App\Sagas;`,
        // which is not PHP at all, and a payload in the saga's own namespace
        // would be refused as living elsewhere, after the wizard had already
        // promised to write it.
        $blueprint = new SagaBlueprint('\App\Sagas\OrderSaga', '\App\Sagas\OrderSubject');

        $blueprint->addInitial('placed');
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Signal,
            'pay',
            ['placed'],
            ['paid'],
            '\App\Sagas\PaymentReceived',
        ));

        $plan = $this->plan($blueprint);

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertStringContainsString('namespace App\Sagas;', self::file($plan, self::SAGA));
        self::assertStringContainsString('final class OrderSaga', self::file($plan, self::SAGA));

        // The subject and the payload are both named with the backslash, and
        // both are in the saga's namespace — so both are written, under the
        // names they will actually have on disk.
        self::assertStringContainsString('namespace App\Sagas;', self::file($plan, self::SUBJECT));
        self::assertStringContainsString('namespace App\Sagas;', self::file($plan, 'PaymentReceived.php'));

        foreach ([self::SAGA, self::LISTENERS, self::SUBJECT, 'PaymentReceived.php'] as $name) {
            self::assertParses($plan, $name);
        }
    }

    public function test_a_signal_awaiting_an_interface_is_neither_refused_nor_given_a_class(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], AwaitablePayload::class));

        $plan = $this->plan($blueprint);

        // `Signal::accepts()` is an `instanceof`, so a contract is a name a step
        // may wait for — the blueprint and the wizard both accept one. Asking
        // `class_exists()` here instead would call an interface sitting right
        // there missing: refused for being in another namespace, and in the
        // saga's own overwritten with a class of the same name.
        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertNull($plan->file('AwaitablePayload.php'));
        self::assertStringContainsString(
            'use Techork\Saga\Tests\Laravel\Console\Fixtures\AwaitablePayload;',
            self::file($plan, self::LISTENERS),
        );
    }

    public function test_a_payload_named_like_the_saga_is_refused_rather_than_written_over_it(): void
    {
        // The plan is written in order — the saga, the listeners, then the
        // DTOs — and each file is put where its name says, so a payload that
        // shares the saga's name does not collide with it at generation time.
        // It collides at write time, and the second write wins: the saga the
        // command had just rendered would come back as a payload stub, in the
        // same run, reported as `updated`.
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Signal,
            'pay',
            ['charged'],
            ['paid'],
            'App\Sagas\OrderSaga',
        ));

        $plan = $this->plan($blueprint);

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'which is the saga itself', true);
        self::assertStringContainsString('final class OrderSaga', self::file($plan, self::SAGA));
        self::assertCount(1, self::named($plan, self::SAGA));
    }

    public function test_a_subject_named_like_the_saga_is_refused_too(): void
    {
        $plan = $this->plan(new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSaga'));

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'Give the saga a subject of its own', true);
        self::assertStringContainsString('final class OrderSaga', self::file($plan, self::SAGA));
        self::assertCount(1, self::named($plan, self::SAGA));
    }

    public function test_a_subject_that_does_not_exist_is_generated_next_to_the_saga(): void
    {
        $plan = $this->plan($this->ordered());

        self::assertStringContainsString('namespace App\Sagas;', self::file($plan, self::SUBJECT));
        self::assertStringContainsString('final class OrderSubject', self::file($plan, self::SUBJECT));
    }

    public function test_a_subject_outside_the_namespace_is_a_warning_rather_than_a_file(): void
    {
        $plan = $this->plan($this->ordered('Other\Ns\OrderSubject'));

        self::assertReports($plan, 'is not in App\Sagas', false);
        self::assertNull($plan->file(self::SUBJECT));
        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
    }

    public function test_a_subject_that_already_exists_is_left_alone(): void
    {
        $blueprint = $this->ordered();
        $plan = $this->plan(new SagaBlueprint('App\Sagas\OrderSaga', ChildSubject::class));

        self::assertNull($plan->file(self::SUBJECT));
        self::assertStringContainsString('use Techork\Saga\Tests\Laravel\Console\Fixtures\ChildSubject;', self::file($plan, self::SAGA));
    }

    // ---------------------------------------------------------------- calls

    public function test_a_call_to_a_child_with_no_arguments_is_built_where_it_is_named(): void
    {
        $plan = $this->plan($this->call(ParameterlessChildSaga::class, ChildSubject::class));

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertStringContainsString(implode("\n", [
            "            new Call('pay', 'charged', 'paid',",
            '                runs: new ParameterlessChildSaga(),',
            '                subject: self::paySubject(...)),',
        ]), self::file($plan, self::SAGA));

        // Nothing to fill in, so nothing is left behind for the author.
        self::assertSame(0, self::countStub(self::file($plan, self::SAGA), 'child', 'pay'));
    }

    public function test_a_call_to_a_child_that_needs_arguments_gets_a_helper_to_fill_in(): void
    {
        $plan = $this->plan($this->call(NeedsArgsChildSaga::class, ChildSubject::class));
        $saga = self::file($plan, self::SAGA);

        self::assertStringContainsString('                runs: $this->payChild(),', $saga);

        // A helper, not a constructor parameter: a parameter would sit in the
        // region the generator rewrites, and a second Call would take the
        // author's wiring with it.
        self::assertStringContainsString(implode("\n", [
            '    // @saga:stub:child:pay',
            '    /**',
            '     * @todo return the child saga "pay" runs — from the container, or built here.',
            '     */',
            '    private function payChild(): NeedsArgsChildSaga',
            '    {',
            '        return app(NeedsArgsChildSaga::class);',
            '    }',
        ]), $saga);
    }

    public function test_a_call_to_a_child_that_is_not_there_yet_gets_a_helper_that_throws(): void
    {
        $plan = $this->plan($this->call('App\Sagas\PaymentIntentSaga', 'App\Sagas\PaymentIntentSubject'));

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertStringContainsString('                runs: $this->payChild(),', self::file($plan, self::SAGA));
        self::assertStringContainsString(implode("\n", [
            '    // @saga:stub:child:pay',
            '    /**',
            '     * @todo App\Sagas\PaymentIntentSaga does not exist yet. Build it first: php artisan make:saga PaymentIntentSaga',
            '     */',
            '    private function payChild(): PaymentIntentSaga',
            '    {',
            "        throw new \\LogicException('Call \"pay\" has no child saga to run yet.');",
            '    }',
        ]), self::file($plan, self::SAGA));

        // The child's subject belongs to the child, so nothing here writes it.
        self::assertNull($plan->file('PaymentIntentSubject.php'));
    }

    public function test_a_call_that_shares_the_parent_subject_writes_an_identity_mapping(): void
    {
        $blueprint = $this->call(ParameterlessChildSaga::class, ChildSubject::class, sharesSubject: true);
        $saga = self::file($this->plan($blueprint), self::SAGA);

        self::assertStringContainsString('                subject: static fn (OrderSubject $s): object => $s),', $saga);
        self::assertSame(0, self::countStub($saga, 'subject', 'pay'));
    }

    public function test_a_call_that_maps_its_own_subject_gets_a_stub_that_throws(): void
    {
        $plan = $this->plan($this->call(ParameterlessChildSaga::class, ChildSubject::class));
        $saga = self::file($plan, self::SAGA);

        // Failing loudly is the honest default: an exception leaves run() and
        // the saga compensates, where a subject that quietly came back
        // unchanged would break the child instead.
        self::assertStringContainsString(implode("\n", [
            '    // @saga:stub:subject:pay',
            '    /**',
            '     * @todo build the subject "pay" runs on, out of this saga\'s.',
            '     */',
            '    private static function paySubject(OrderSubject $subject): ChildSubject',
            '    {',
            "        throw new \\LogicException('Call \"pay\" has no subject mapping yet.');",
            '    }',
        ]), $saga);
    }

    public function test_a_call_action_reads_the_childs_final_subject(): void
    {
        $blueprint = $this->call(ParameterlessChildSaga::class, ChildSubject::class);
        $listeners = self::file($this->plan($blueprint), self::LISTENERS);

        self::assertStringContainsString(implode("\n", [
            '            // @saga:stub:action:pay',
            "            'workflow.'.OrderSaga::class.'.transition.pay' => static function (TransitionEvent \$event): void {",
            '                $child = Signal::payload($event, ChildSubject::class);',
            '',
            '                // @todo copy what this saga needs out of $child — an outcome is data, not a second edge',
            '            },',
        ]), $listeners);
    }

    public function test_a_child_saga_that_cannot_be_instantiated_is_refused(): void
    {
        $blueprint = $this->call(AbstractChildSaga::class, ChildSubject::class);

        $plan = $this->plan($blueprint);

        // Not a helper left to fill in: no helper can produce an instance of an
        // abstract class, so the step is refused before anything is written.
        self::assertTrue($plan->isBlocked(), self::text($plan->issues));
        self::assertReports($plan, 'cannot be instantiated', true);
    }

    public function test_a_call_to_a_child_that_is_an_interface_reaches_for_the_container(): void
    {
        $plan = $this->plan($this->call(ChildSagaContract::class, ChildSubject::class));

        // An interface is not a child that failed to be a class. It is the shape
        // a container is asked for, so the stub resolves it — and it does not
        // tell the author to generate the file they already wrote.
        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertStringNotContainsString('does not exist yet', self::text($plan->issues));

        $saga = self::file($plan, self::SAGA);

        self::assertStringContainsString('    private function payChild(): ChildSagaContract', $saga);
        self::assertStringContainsString('        return app(ChildSagaContract::class);', $saga);
        self::assertStringNotContainsString('no child saga to run yet', $saga);
    }

    public function test_a_class_stub_whose_marker_went_missing_is_reported_rather_than_redeclared(): void
    {
        $blueprint = $this->call(ChildSagaContract::class, ChildSubject::class);
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace("    // @saga:stub:child:pay\n", '', $files[self::SAGA]);

        $plan = $this->plan($blueprint, $files);
        $saga = self::file($plan, self::SAGA);

        // The marker is the only link in the listeners, where a second entry
        // collapses into the map's own key. Here the stub declares a method, and
        // a second `function payChild()` is a file PHP refuses to load — so the
        // declaration is checked too, and the stub is reported instead of added.
        self::assertSame(1, substr_count($saga, 'function payChild('));
        self::assertReports($plan, 'already declares payChild()', false);
        self::assertFalse($plan->isBlocked(), self::text($plan->issues));

        // And the file the author has is still the file they get back.
        self::assertSame($files[self::SAGA], $saga);
    }

    /**
     * A Call step out of `charged`, on top of the ordinary graph.
     */
    private function call(string $childSaga, string $childSubject, bool $sharesSubject = false): SagaBlueprint
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['charged'],
            ['paid'],
            childSaga: $childSaga,
            childSubject: $childSubject,
            childSharesSubject: $sharesSubject,
        ));

        return $blueprint;
    }

    // ---------------------------------------------------------------- imports

    public function test_two_classes_with_one_short_name_are_refused(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], PaymentReceived::class));
        $blueprint->addPlace('done');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'settle',
            ['paid'],
            ['done'],
            childSaga: ParameterlessChildSaga::class,
            childSubject: 'Other\Ns\PaymentReceived',
        ));

        $plan = $this->plan($blueprint);

        self::assertTrue($plan->isBlocked(), self::text($plan->issues));
        self::assertReports($plan, 'would be imported as PaymentReceived', true);
    }

    public function test_a_class_named_like_the_file_it_would_be_imported_into_is_refused(): void
    {
        $plan = $this->plan($this->call('Other\Ns\OrderSaga', 'Other\Ns\OrderSubject'));

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, "the name of this file's own class", true);
    }

    public function test_an_import_the_author_added_by_hand_is_carried_forward(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        // The head above the marker is regenerated wholesale, which would drop
        // an import someone typed there — and dropping one turns a working file
        // into a fatal error at the next request, not into a compile error now.
        $files[self::LISTENERS] = str_replace(
            'use Techork\Saga\Event\CompensateEvent;',
            "use Techork\Saga\Event\CompensateEvent;\nuse App\Models\Order as OrderModel;",
            $files[self::LISTENERS],
        );

        $second = $this->plan($blueprint, $files);
        $listeners = self::file($second, self::LISTENERS);

        self::assertStringContainsString('use App\Models\Order as OrderModel;', $listeners);
        // Carried, not duplicated.
        self::assertSame(1, substr_count($listeners, 'use Techork\Saga\Event\CompensateEvent;'));
        self::assertSame(1, substr_count($listeners, 'use App\Models\Order as OrderModel;'));

        // And carrying it did not make the file differ from itself next time.
        self::assertSame(self::files($second), self::files($this->plan($blueprint, self::files($second))));
    }

    public function test_a_trait_use_in_the_class_body_is_not_carried_up_as_an_import(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace(
            '    // @saga:generated:helpers:end',
            "    use HasOrderState;\n\n    // @saga:generated:helpers:end",
            $files[self::SAGA],
        );

        $plan = $this->plan($blueprint, $files);
        $saga = self::file($plan, self::SAGA);

        // A trait's `use` is not an import — but it looks like one, and hoisting
        // it above the class makes it one: `use HasOrderState;` under
        // `namespace App\Sagas;` aliases the trait to a global class, and the
        // class body's own `use` is then resolved through that alias. A file
        // that loaded becomes one that does not.
        self::assertStringNotContainsString("\nuse HasOrderState;", $saga);
        self::assertSame(1, substr_count($saga, 'use HasOrderState;'));
        self::assertStringContainsString("    use HasOrderState;", $saga);

        // Read back once more: the statement is matched once, not twice, so the
        // run after this one is not refused for a name colliding with itself.
        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertFalse($this->plan($blueprint, self::files($plan))->isBlocked());
    }

    public function test_the_same_import_typed_with_a_leading_backslash_is_a_duplicate_not_a_clash(): void
    {
        // `use \Techork\Saga\Saga;` and `use Techork\Saga\Saga;` name one class.
        // The head is rewritten on every run, so the author's line is dropped
        // rather than carried — and the run is not refused for a name that
        // collides with nothing. A refusal here would be unmovable: `--force`
        // answers a lost edit, not a file that cannot be written.
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace(
            'use Techork\Saga\Saga;',
            'use \Techork\Saga\Saga;',
            $files[self::SAGA],
        );

        $plan = $this->plan($blueprint, $files);
        $saga = self::file($plan, self::SAGA);

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertSame(1, substr_count($saga, 'Saga;'));
        self::assertStringContainsString("\nuse Techork\Saga\Saga;", $saga);
        self::assertStringNotContainsString('use \Techork\Saga\Saga;', $saga);
    }

    public function test_a_definition_edited_to_match_its_blueprint_is_no_longer_drift(): void
    {
        // The refusal tells the author to move the change into the blueprint.
        // Doing that means editing both the block and the method, and the
        // rewrite then puts back exactly what is already there — so there is no
        // edit to lose. Refusing that would be a trap rather than a safeguard:
        // the stored hash only moves when a write succeeds, and the refusal is
        // the very thing stopping one, so the file could never be written again
        // without `--force`.
        $old = $this->ordered();
        $pristine = self::files($this->plan($old));
        $was = SagaBlueprint::fromSource($pristine[self::SAGA]);

        self::assertNotNull($was);

        // The author adds a step to the graph and types the same step into the
        // method — the region on disk is now what the new graph renders.
        $new = $this->ordered();
        $new->addPlace('paid');
        $new->addStep(new SagaStep(SagaStepKind::Transition, 'pay', ['charged'], ['paid']));

        $edited = $pristine;
        $edited[self::SAGA] = self::withRegion(
            $edited[self::SAGA],
            self::region(self::files($this->plan($new))[self::SAGA]),
        );

        // With the block left as the last write left it, the region is a hand
        // edit and is refused — the check the previous test pins.
        $refused = $this->plan($old, $edited);

        self::assertTrue($refused->isBlocked());
        self::assertReports($refused, 'matches neither its blueprint', true);

        // With the block brought up to date too, the two agree, and the
        // rewrite is the region written over itself.
        $new->stamp(SagaBlueprint::RENDERER_VERSION, $was->bodyHash());
        $edited[self::SAGA] = self::withBlueprint($edited[self::SAGA], $new);

        $plan = $this->plan($new, $edited);

        self::assertFalse($plan->isBlocked(), self::text($plan->issues));
        self::assertStringContainsString("'paid'", self::file($plan, self::SAGA));
        self::assertParses($plan, self::SAGA);
    }

    public function test_a_payload_file_already_on_disk_is_not_written_over(): void
    {
        // The class does not exist, so the generator would normally write it —
        // but the file is already there, which means somebody wrote it and the
        // class merely does not load. A DTO is the one thing written once and
        // never again; overwriting it is the failure mode this whole file is
        // arranged against.
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Signal,
            'pay',
            ['charged'],
            ['paid'],
            'App\Sagas\PaymentReceived',
        ));

        $first = self::files($this->plan($blueprint));
        $theirs = ['PaymentReceived.php' => "<?php\n\n// mine, and half-finished\n"];

        $plan = $this->plan($blueprint, [...$first, ...$theirs]);

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'writes that file once and never again', true);
        self::assertNull($plan->file('PaymentReceived.php'));

        // The same file as a previous run left it is not a conflict: it comes
        // back into the plan so the write can report it as unchanged.
        $second = $this->plan($blueprint, $first);

        self::assertFalse($second->isBlocked(), self::text($second->issues));
        self::assertSame(
            self::file($this->plan($blueprint), 'PaymentReceived.php'),
            self::file($second, 'PaymentReceived.php'),
        );
    }

    public function test_an_author_import_that_shadows_a_generated_one_is_refused(): void
    {
        $blueprint = $this->ordered();
        $files = self::files($this->plan($blueprint));

        $files[self::SAGA] = str_replace(
            'use Techork\Saga\Saga;',
            "use Techork\Saga\Saga;\nuse Other\Ns\Saga;",
            $files[self::SAGA],
        );

        $plan = $this->plan($blueprint, $files);

        self::assertTrue($plan->isBlocked());
        self::assertReports($plan, 'the same name', true);
    }
}
