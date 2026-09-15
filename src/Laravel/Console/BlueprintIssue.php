<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

/**
 * One thing the wizard noticed about a graph it is building.
 *
 * The split into errors and warnings is the difference between "this cannot
 * work" and "this works, but probably not how you meant it". An error blocks
 * the step, because every rule behind one is a rule the runtime enforces
 * somewhere — `start()` refuses duplicate transition names, a place that is
 * not declared is silently invented by Symfony's own `Definition`, an awaited
 * class that does not exist parks the saga forever. A warning is something no
 * runtime can check: whether a guard will keep an `expire` from firing the
 * moment the saga reaches the place it was meant to wait in.
 *
 * Only the caller decides what to do with them; this type carries no policy.
 */
final readonly class BlueprintIssue
{
    private function __construct(
        public string $message,
        public bool $isError,
    ) {}

    public static function error(string $message): self
    {
        return new self($message, true);
    }

    public static function warning(string $message): self
    {
        return new self($message, false);
    }
}
