<?php

declare(strict_types=1);

namespace Techork\Saga;

use function array_shift;

final class InMemorySagaQueue implements SagaQueue
{
    /** @var list<array{class: class-string<Saga>, id: string, transition: string}> */
    private array $messages = [];

    public function push(string $sagaClass, string $sagaId, string $transition): void
    {
        $this->messages[] = ['class' => $sagaClass, 'id' => $sagaId, 'transition' => $transition];
    }

    /** @return array{class: class-string<Saga>, id: string, transition: string}|null */
    public function pop(): ?array
    {
        return array_shift($this->messages);
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /** @return list<array{class: class-string<Saga>, id: string, transition: string}> */
    public function all(): array
    {
        return $this->messages;
    }
}
