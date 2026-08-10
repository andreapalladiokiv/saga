<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

/** A realistically-shaped subject: a typed nested value object and an array. */
final class NestedSubject
{
    /** @param list<string> $lines */
    public function __construct(
        public Amount $total,
        public array $lines = [],
    ) {}
}
