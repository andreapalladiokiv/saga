<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

use Techork\Saga\Saga;

/**
 * A child saga a Call runs, declared as a contract rather than a class.
 *
 * `Techork\Saga\Saga` is itself an interface, so this is a plain extension of
 * it and passes every check the blueprint makes. It is not instantiable, which
 * is the point: the generator has to reach for the container here, exactly as it
 * does for a class whose constructor needs arguments — and not tell the author
 * to run `make:saga` for a file they already wrote, which is what answering
 * "does this exist" with `class_exists()` alone would do.
 */
interface ChildSagaContract extends Saga {}
