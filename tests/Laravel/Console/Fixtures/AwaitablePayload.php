<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

/**
 * A payload a step may legitimately wait for, declared as an interface.
 *
 * `Signal::accepts()` is an `instanceof` against the awaited name, so an
 * interface satisfies it exactly as a class does — a payload may be any
 * implementation, and naming the contract rather than one implementation is a
 * reasonable thing for a saga to do. The blueprint and the wizard have always
 * accepted one; this fixture exists because the scaffolder has to answer the
 * same question, and answering it with `class_exists()` alone would call this
 * interface missing — refusing a sound graph, or writing a class over it.
 */
interface AwaitablePayload
{
    public function reference(): string;
}
