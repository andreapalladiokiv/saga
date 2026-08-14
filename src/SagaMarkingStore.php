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
     * it is discarded because there is nowhere for it to go.
     *
     * What Symfony means it for is worth knowing, since it is not simply ignored
     * upstream: {@see \Symfony\Component\Workflow\MarkingStore\MethodMarkingStore}
     * forwards it to the subject's own marking setter, so a domain object exposing
     * `setMarking(string|array|\BackedEnum $places, array $context = [])` receives
     * the apply-time metadata and may record who moved it and why. Only the method
     * accessor gets it; the property accessor drops it.
     *
     * That door does not exist here. This store keeps the marking in a
     * {@see WeakMap} precisely so subjects stay plain DTOs with no marking
     * property and no setter to call — so there is no setter to hand $context to.
     *
     * $context is Symfony's APPLY CONTEXT: the array a caller passes to
     * `Workflow::apply()` and that listeners can rewrite with
     * `$event->setContext()`. It exists for the duration of one apply(), and it is
     * handed over here mid-apply — before `entered`, `completed` and `announce` —
     * so it is not even the final value. It is not a place to keep anything:
     * {@see SagaState} persists marking, subject, history and version, and
     * long-lived run state belongs on the SUBJECT.
     *
     * Writing to it is not refused — Symfony carries it along the phases of one
     * apply, so passing something from a transition listener to an entered one is
     * the channel working as designed, and it is indistinguishable from an attempt
     * to remember. Only the second is a mistake, and it is a mistake this docblock
     * exists to name rather than one the runner can catch.
     *
     * @param  array<string, mixed>  $context  Symfony's per-apply context; discarded
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $this->markings[$subject] = $marking;
    }
}
