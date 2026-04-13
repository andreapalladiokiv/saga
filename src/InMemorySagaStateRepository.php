<?php

declare(strict_types=1);

namespace Techork\Saga;

final class InMemorySagaStateRepository implements SagaStateRepository
{
    /** @var array<string, SagaState> */
    private array $states = [];

    public function load(string $id): ?SagaState
    {
        return $this->states[$id] ?? null;
    }

    public function save(SagaState $state): void
    {
        $this->states[$state->id] = $state;
    }

    public function delete(string $id): void
    {
        unset($this->states[$id]);
    }
}
