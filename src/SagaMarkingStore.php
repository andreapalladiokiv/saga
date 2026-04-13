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
     * @param  array<string, mixed>  $context
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $this->markings[$subject] = $marking;
    }
}
