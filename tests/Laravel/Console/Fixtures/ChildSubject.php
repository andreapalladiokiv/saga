<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

/**
 * The subject of a child saga a generated Call runs.
 *
 * It exists so the scaffolder's Call tests can name a real class for the
 * child's subject: the generated action stub types its
 * `Signal::payload($event, ChildSubject::class)` with it, and a Call whose
 * child subject does not exist is a warning about code that cannot be written
 * yet rather than a step that cannot be rendered at all.
 */
final class ChildSubject
{
    public string $reference = '';
}
