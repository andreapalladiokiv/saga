<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

/**
 * Plain serializable subject used across SagaRunner tests. Public mutable
 * fields stand in for whatever a real saga's typed subject would carry —
 * the saga library only requires that the subject is an object and survives
 * `serialize()`/`unserialize()` (we don't actually serialize in unit tests
 * since {@see \Techork\Saga\InMemorySagaStateRepository} keeps the same
 * instance, but the contract is preserved).
 */
class TestSubject
{
    public string $path = '';

    public string $outcome = '';

    public int $counter = 0;

    /** @var list<string> */
    public array $branchLog = [];
}
