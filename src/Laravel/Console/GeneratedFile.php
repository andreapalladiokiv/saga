<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

/**
 * One file the generator wants to write, with everything in it.
 *
 * The path is a bare file name rather than a path: the generator works out what
 * a saga's files are, and the command works out where they go. Keeping the two
 * apart is what lets the whole of the generation be tested without a filesystem.
 *
 * A file is not necessarily written: the scaffolder reports every file every
 * time, and the command skips the ones whose contents already match what is on
 * disk. Nothing here is a diff.
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $name,
        public string $contents,
    ) {}
}
