<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * What one step wants done to OTHER sagas once its lock is released.
 *
 * The runner puts a fresh one in the apply context of every step, collects what
 * lands in it, and executes the contents after {@see SagaLock::withLock()}
 * returns — the same place, and for the same reason, that queue pushes happen
 * there: touching a second saga while holding the first one's lock is what
 * deadlocks an inline driver.
 *
 * It rides the context rather than living on the runner because
 * {@see SagaRunner} is readonly, and because a per-step object is exactly the
 * right lifetime: a step that throws never persists, and its outbox is dropped
 * with it, so nothing it asked for happens. That is the whole transactional
 * story — the actions here are visible only if the step committed.
 *
 * @internal
 */
final class SagaOutbox
{
    /** @var list<LaunchChild|DeliverReply> */
    private array $actions = [];

    public function add(LaunchChild|DeliverReply $action): void
    {
        $this->actions[] = $action;
    }

    /** @return list<LaunchChild|DeliverReply> launches first: an answer cannot precede its child */
    public function actions(): array
    {
        $launches = [];
        $replies = [];

        foreach ($this->actions as $action) {
            if ($action instanceof LaunchChild) {
                $launches[] = $action;
            } else {
                $replies[] = $action;
            }
        }

        return [...$launches, ...$replies];
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }
}
