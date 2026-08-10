<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

/** Subclasses used to be refused: allowed_classes matches names, not types. */
final class PrioritySubject extends TestSubject
{
    public int $tier = 1;
}
