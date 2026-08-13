<?php

declare(strict_types=1);

namespace Techork\Saga;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use WeakMap;

/**
 * Keeps the workflow marking outside the saga subject.
 *
 * Subjects stay clean DTOs — no `marking` property leaking workflow concerns.
 * Marking is held in a {@see WeakMap} keyed by subject; when the subject goes
 * out of scope the entry is garbage-collected automatically.
 *
 * {@see SagaRunner} binds the marking before applying a transition and reads
 * it back afterwards.
 */
final class SagaMarkingStore implements MarkingStoreInterface
{
    /** @var WeakMap<object, Marking> */
    private WeakMap $markings;

    public function __construct()
    {
        $this->markings = new WeakMap();
    }

    public function getMarking(object $subject): Marking
    {
        return $this->markings[$subject] ?? new Marking();
    }

    /**
     * Stores the marking, and deliberately drops $context.
     *
     * The argument is here because {@see MarkingStoreInterface} declares it, and
     * it is discarded on purpose. Symfony's own marking stores discard it too, so
     * the behaviour is conventional — but conventional and obvious are different
     * things, and this one has caught the author of this package.
     *
     * $context is Symfony's APPLY CONTEXT: the array a caller passes to
     * `Workflow::apply()` and that listeners can rewrite with
     * `$event->setContext()`. It exists for the duration of one apply(). It is
     * not a place to keep anything: {@see SagaState} persists marking, subject,
     * history and version, and there is no fifth column for this, by choice —
     * long-lived run state belongs on the SUBJECT, and a second home for the same
     * fact only raises the question of which of the two is right.
     *
     * A listener that writes to it anyway is refused rather than quietly ignored;
     * see {@see SagaRunner::assertNothingWasStashedInTheApplyContext()}.
     *
     * @param  array<string, mixed>  $context  Symfony's per-apply context; discarded
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $this->markings[$subject] = $marking;
    }
}
