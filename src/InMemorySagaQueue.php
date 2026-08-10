<?php

declare(strict_types=1);

namespace Techork\Saga;

use function array_shift;

final class InMemorySagaQueue implements SagaQueue
{
    /** @var list<array{class: class-string<Saga>, id: string, transition: string, delay: int}> */
    private array $messages = [];

    public function push(string $sagaClass, string $sagaId, string $transition, int $delaySeconds = 0): void
    {
        $this->messages[] = [
            'class' => $sagaClass,
            'id' => $sagaId,
            'transition' => $transition,
            'delay' => $delaySeconds,
        ];
    }

    /** @return array{class: class-string<Saga>, id: string, transition: string, delay: int}|null */
    public function pop(): ?array
    {
        return array_shift($this->messages);
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /** @return list<array{class: class-string<Saga>, id: string, transition: string, delay: int}> */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * Transition names currently queued, in push order — the shape assertions
     * about fan-out actually care about.
     *
     * @return list<string>
     */
    public function transitions(): array
    {
        return \array_column($this->messages, 'transition');
    }
}
