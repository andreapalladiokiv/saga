<?php

declare(strict_types=1);

namespace Techork\Saga;

interface SagaStateRepository
{
    public function load(string $id): ?SagaState;

    public function save(SagaState $state): void;

    public function delete(string $id): void;
}
