<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

/**
 * What {@see SagaScaffolder::plan()} decided: the files, and everything it had
 * to say about them.
 *
 * Both halves are needed together. The files are complete and correct on their
 * own; the issues are what the author has to hear — that the file on disk was
 * hand-edited, that a class the graph names cannot be generated where it says,
 * that a stub in the listeners file outlived the step it belonged to. A caller
 * decides whether an error blocks the write ({@see isBlocked()}); nothing here
 * withholds a file.
 */
final readonly class ScaffoldingPlan
{
    /**
     * @param  list<GeneratedFile>  $files
     * @param  list<BlueprintIssue>  $issues
     */
    public function __construct(
        public array $files,
        public array $issues,
    ) {}

    /**
     * Whether anything found is an error rather than advice.
     *
     * The command checks this before it writes: an error here means the files
     * would either clobber an edit or refer to code that cannot exist, and
     * neither is a thing to do quietly.
     */
    public function isBlocked(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isError) {
                return true;
            }
        }

        return false;
    }

    public function file(string $name): ?GeneratedFile
    {
        foreach ($this->files as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }

        return null;
    }
}
