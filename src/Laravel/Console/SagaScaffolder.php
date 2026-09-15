<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

use JsonException;
use ReflectionClass;
use Techork\Saga\SagaException;

use function array_map;
use function array_slice;
use function array_unique;
use function class_exists;
use function count;
use function explode;
use function file_get_contents;
use function hash;
use function implode;
use function in_array;
use function interface_exists;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function strpos;
use function strrpos;
use function strlen;
use function substr;
use function trim;

/**
 * Turns a blueprint into the files that hold it.
 *
 * Three rules shape everything here, and each is a consequence of the same
 * fact: a generated saga is a file its author keeps working in.
 *
 *  1. What the blueprint can be rewritten from, the blueprint rewrites.
 *     Everything above the marker that opens a preserved region — the docblock,
 *     the class declaration, `definition()` — is produced from the graph every
 *     time. There is no merge to get wrong.
 *  2. What the author writes, the command only ever adds to. Between the
 *     markers nothing here rewrites, reorders or deletes a line. New stubs are
 *     inserted at the closing marker, so the region grows in the order the
 *     wizard was driven.
 *  3. A refusal is not a failure. A hand edit to `definition()`, a class the
 *     graph names that cannot be generated where it says, a stub whose step is
 *     gone — each is reported with what it means and what to do, and the caller
 *     decides. Nothing here is thrown away quietly, and nothing is thrown away.
 *
 * The one thing that lives above the marker and is still the author's is an
 * import they added by hand — a `use` statement at the top of the file, which is
 * the only place one can be. Those are carried forward rather than regenerated,
 * because dropping one turns a working file into a fatal error at the next
 * deploy — see {@see importBlock()}. A `use` inside the class body is a trait
 * rather than an import, and is left exactly where the author put it.
 *
 * Like the blueprint, this class imports nothing from Illuminate: it is a pure
 * function from a graph and a directory's contents to a plan, and that is what
 * lets every rule above be tested without a container or a filesystem. The only
 * thing it reads is its own stub templates, which are part of the package.
 */
final class SagaScaffolder
{
    /**
     * The pair around the region the blueprint rewrites, and the pairs around
     * the regions it only appends to.
     *
     * Each pair is a seam, not a fence: the text ABOVE the opening marker is
     * regenerated, the text from it on belongs to the author, and a new stub is
     * inserted just above the closing marker. The closing marker is therefore
     * the top of the generated content and the bottom of the author's, which is
     * why the two markers of a pair sit next to each other in a fresh file.
     *
     * They are public because a test — and the README — has to be able to name
     * them without restating the text.
     */
    public const DEFINITION_START = '// @saga:generated:definition:start';

    public const DEFINITION_END = '// @saga:generated:definition:end';

    /** Opens the author's region inside the saga class. Runs to the end of the file. */
    public const HELPERS_BEGIN = '// @saga:generated:helpers:begin';

    /** Closes it. New helper methods are inserted directly above this line. */
    public const HELPERS_END = '// @saga:generated:helpers:end';

    /** The same pair, inside the listeners map. */
    public const LISTENERS_BEGIN = '// @saga:listeners:begin';

    public const LISTENERS_END = '// @saga:listeners:end';

    /** One stub, marked so it is written exactly once: `@saga:stub:<kind>:<step>`. */
    public const STUB_PATTERN = '~@saga:stub:([a-z]+):([a-z0-9_]+)~';

    /**
     * Everything the generator would write for this graph, and everything it
     * has to say about it.
     *
     * `$files` is the target directory as it is on disk — name to contents. It
     * is read for four things and no more: whether a generated file is already
     * there, what its preserved regions still hold, which stubs an earlier run
     * left behind, and which imports the author added by hand.
     *
     * @param  array<string, string>  $files
     */
    public function plan(SagaBlueprint $blueprint, array $files = [], bool $force = false): ScaffoldingPlan
    {
        $issues = [];
        $namespace = self::namespaceOf($blueprint->saga());
        $class = self::shortName($blueprint->saga());
        $sagaName = $class . '.php';
        $listenersName = $class . 'Listeners.php';

        if ($namespace === '') {
            $issues[] = BlueprintIssue::error(sprintf(
                'The saga %s has no namespace, and a generated class needs one — the file it goes in '
                . 'declares it. Move the saga into a namespace, or pass one to the command.',
                $blueprint->saga(),
            ));
        }

        // The files that are being written to MAKE something true come first,
        // because the graph is judged against what will exist rather than what
        // does. A payload DTO is generated exactly when the class a Signal
        // awaits is not there yet, and the rule that reports that would
        // otherwise refuse the very step the payload is being written for —
        // `promise()` is how {@see dtoFiles()} says so, and it is the same
        // thing the wizard says when the author agrees to the file.
        $dtos = $this->dtoFiles($blueprint, $namespace, $files, $issues);

        // Then the graph's own rules: rendering a definition from a graph the
        // runtime would reject writes a file that is broken the moment it is
        // loaded, and a caller holding a blueprint should not also have to
        // remember to ask whether it is a usable one.
        foreach ($blueprint->validate() as $issue) {
            $issues[] = $issue;
        }

        // Rendered first, hashed, and only then wrapped in a docblock: the hash
        // covers `definition()` alone, so it is the same number whether or not
        // anything around it changed. Stamping the blueprint before the docblock
        // is rendered is what puts that number in the file.
        $definition = self::indent($this->renderDefinition($blueprint));
        $blueprint->stamp(SagaBlueprint::RENDERER_VERSION, self::hash($definition));

        $existingSaga = $files[$sagaName] ?? null;
        $existingListeners = $files[$listenersName] ?? null;

        if ($existingSaga !== null) {
            $this->reportDrift($existingSaga, $definition, $force, $issues);
        }

        $generated = [
            new GeneratedFile(
                $sagaName,
                $this->sagaFile($blueprint, $namespace, $class, $definition, $existingSaga, $issues),
            ),
            new GeneratedFile(
                $listenersName,
                $this->listenersFile($blueprint, $namespace, $class, $existingListeners, $issues),
            ),
        ];

        foreach ($dtos as $file) {
            $generated[] = $file;
        }

        $this->reportOrphans($existingSaga, $existingListeners, $blueprint, $issues);

        return new ScaffoldingPlan($generated, $issues);
    }

    // ---------------------------------------------------------------- the saga class

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    private function sagaFile(
        SagaBlueprint $blueprint,
        string $namespace,
        string $class,
        string $definition,
        ?string $existing,
        array &$issues,
    ): string {
        $fresh = $this->stub('saga.class.php', [
            'namespace' => $namespace,
            'class' => $class,
            'imports' => $this->importBlock(
                $this->sagaImports($blueprint),
                $namespace,
                $class,
                self::headOf($existing, self::HELPERS_BEGIN),
                $issues,
            ),
            'blueprint' => implode("\n", $blueprint->toDocblockLines()),
            'definition' => $definition,
        ]);

        $source = $existing === null
            ? $fresh
            : $this->regenerateDownTo($fresh, $existing, self::HELPERS_BEGIN, $issues);

        return $this->appendStubs($source, self::HELPERS_END, $this->helperStubs($blueprint, $issues), $issues);
    }

    /**
     * The classes the rendered `definition()` names, and the ones the stubs it
     * carries do.
     *
     * @return list<string>
     */
    private function sagaImports(SagaBlueprint $blueprint): array
    {
        $classes = ['Symfony\Component\Workflow\Definition', 'Techork\Saga\Saga'];

        foreach ($blueprint->steps() as $step) {
            $classes[] = match ($step->kind) {
                SagaStepKind::Transition => 'Symfony\Component\Workflow\Transition',
                SagaStepKind::Signal => 'Techork\Saga\Signal',
                SagaStepKind::Call => 'Techork\Saga\Call',
            };

            foreach ([$step->awaits, $step->childSaga, $step->childSubject] as $class) {
                if ($class !== null) {
                    $classes[] = $class;
                }
            }
        }

        // The parent subject is named by every Call's mapping, and by the stub
        // that stands in for one written by hand.
        $classes[] = $blueprint->subject();

        return $classes;
    }

    /**
     * The `definition()` method, as literals.
     *
     * A list of one place is written as a bare string and a list of several as
     * an array, because that is how the graph reads: `'placed'` is a step that
     * starts somewhere, `['approved', 'rejected']` is a fork. Symfony accepts
     * either, so this is the one piece of formatting chosen for the reader
     * rather than the compiler.
     */
    private function renderDefinition(SagaBlueprint $blueprint): string
    {
        $lines = [
            'public function definition(): Definition',
            '{',
            '    return new Definition(',
            '        ' . self::places($blueprint->places()) . ',',
            '        [',
        ];

        foreach ($blueprint->steps() as $step) {
            $lines[] = $this->renderStep($step, $blueprint->subject());
        }

        $lines[] = '        ],';
        $lines[] = '        ' . self::places($blueprint->initial()) . ',';
        $lines[] = '    );';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function renderStep(SagaStep $step, string $parentSubject): string
    {
        $from = self::places($step->from);
        $to = self::places($step->to);

        return match ($step->kind) {
            SagaStepKind::Transition => sprintf(
                "            new Transition('%s', %s, %s),",
                $step->name,
                $from,
                $to,
            ),
            SagaStepKind::Signal => sprintf(
                "            new Signal('%s', %s, %s, %s::class),",
                $step->name,
                $from,
                $to,
                self::shortName((string) $step->awaits),
            ),
            SagaStepKind::Call => $this->renderCall($step, $parentSubject, $from, $to),
        };
    }

    /**
     * A Call carries more than a name and two places, and every part of it is
     * either resolvable now or a stub the author finishes by hand.
     */
    private function renderCall(SagaStep $step, string $parentSubject, string $from, string $to): string
    {
        $subject = $step->sharesSubjectWithChild()
            ? sprintf('subject: static fn (%s $s): object => $s', self::shortName($parentSubject))
            : sprintf('subject: self::%sSubject(...)', $step->name);

        return implode("\n", [
            sprintf("            new Call('%s', %s, %s,", $step->name, $from, $to),
            sprintf('            runs: %s,', $this->childExpression($step)),
            sprintf('            %s),', $subject),
        ]);
    }

    private function childExpression(SagaStep $step): string
    {
        return self::isConstructible($step->childSaga)
            ? 'new ' . self::shortName((string) $step->childSaga) . '()'
            : sprintf('$this->%sChild()', $step->name);
    }

    // ---------------------------------------------------------------- the listeners

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    private function listenersFile(
        SagaBlueprint $blueprint,
        string $namespace,
        string $class,
        ?string $existing,
        array &$issues,
    ): string {
        $fresh = $this->stub('saga.listeners.php', [
            'namespace' => $namespace,
            'class' => $class,
            'imports' => $this->importBlock(
                $this->listenerImports($blueprint),
                $namespace,
                $class . 'Listeners',
                self::headOf($existing, self::LISTENERS_BEGIN),
                $issues,
            ),
        ]);

        // Everything from the opening marker on is the author's — the entries
        // they wrote by hand, the stubs an earlier run added, and the map's own
        // closing brackets. Only the head above it is rewritten.
        $source = $existing === null
            ? $fresh
            : $this->regenerateDownTo($fresh, $existing, self::LISTENERS_BEGIN, $issues);

        return $this->appendStubs($source, self::LISTENERS_END, $this->stepStubs($blueprint), $issues);
    }

    /**
     * @return list<string>
     */
    private function listenerImports(SagaBlueprint $blueprint): array
    {
        $classes = ['Illuminate\Contracts\Events\Dispatcher'];

        foreach ($blueprint->steps() as $step) {
            if ($step->kind !== SagaStepKind::Transition) {
                $classes[] = 'Techork\Saga\Signal';
            }

            foreach ([$step->awaits, $step->childSubject] as $class) {
                if ($class !== null) {
                    $classes[] = $class;
                }
            }

            if ($this->needsGuard($blueprint, $step)) {
                $classes[] = 'Symfony\Component\Workflow\Event\GuardEvent';
            }
        }

        if ($blueprint->steps() !== []) {
            $classes[] = 'Techork\Saga\Event\CompensateEvent';
            $classes[] = 'Symfony\Component\Workflow\Event\TransitionEvent';
        }

        return $classes;
    }

    /**
     * One stub per thing that can happen to a step, in the order it happens.
     *
     * A guard is generated only where the graph needs one: an ordinary
     * transition leaving a place the saga also waits in. Such a transition is
     * queued the moment the saga arrives, so without a guard the saga never
     * waits there at all — that is the one shape where a guard is not optional.
     * Everywhere else a blocking guard is a bug, and the author can still write
     * one.
     *
     * @return list<array{marker: string, code: string}>
     */
    private function stepStubs(SagaBlueprint $blueprint): array
    {
        $class = self::shortName($blueprint->saga());
        $stubs = [];

        foreach ($blueprint->steps() as $step) {
            if ($this->needsGuard($blueprint, $step)) {
                $stubs[] = [
                    'marker' => sprintf('@saga:stub:guard:%s', $step->name),
                    'code' => implode("\n", [
                        sprintf('            // @saga:stub:guard:%s', $step->name),
                        sprintf("            'workflow.'.%s::class.'.guard.%s' => static function (GuardEvent \$event): void {", $class, $step->name),
                        '                // @todo block this until the deadline has passed, or the saga never waits here at all',
                        '            },',
                    ]),
                ];
            }

            $stubs[] = $this->actionStub($step, $class);

            $stubs[] = [
                'marker' => sprintf('@saga:stub:compensate:%s', $step->name),
                'code' => implode("\n", [
                    sprintf('            // @saga:stub:compensate:%s', $step->name),
                    sprintf("            'saga.'.%s::class.'.compensate.%s' => static function (CompensateEvent \$event): void {", $class, $step->name),
                    '                // @todo undo the step. Its subject is on the event: $event->subject',
                    '            },',
                ]),
            ];
        }

        return $stubs;
    }

    /**
     * @return array{marker: string, code: string}
     */
    private function actionStub(SagaStep $step, string $class): array
    {
        $listener = sprintf(
            "            'workflow.'.%s::class.'.transition.%s' => static function (TransitionEvent \$event): void {",
            $class,
            $step->name,
        );

        $body = match ($step->kind) {
            SagaStepKind::Transition => [
                '                // @todo apply the step, and fold what it changed into the subject',
            ],
            // What a Signal is handed, and what a Call is fired with once its
            // child finishes, are the same thing: a payload read through
            // instanceof. The only difference is which class it is.
            SagaStepKind::Signal => [
                sprintf('                $payload = Signal::payload($event, %s::class);', self::shortName((string) $step->awaits)),
                '',
                '                // @todo fold $payload into the subject — a payload never survives its own apply',
            ],
            SagaStepKind::Call => [
                sprintf('                $child = Signal::payload($event, %s::class);', self::shortName((string) $step->childSubject)),
                '',
                '                // @todo copy what this saga needs out of $child — an outcome is data, not a second edge',
            ],
        };

        return [
            'marker' => sprintf('@saga:stub:action:%s', $step->name),
            'code' => implode("\n", [
                sprintf('            // @saga:stub:action:%s', $step->name),
                $listener,
                ...$body,
                '            },',
            ]),
        ];
    }

    /**
     * Whether the ordinary transition this step is needs a guard.
     *
     * Only for a place that also holds the saga still — one a Signal or a Call
     * also leaves. Nothing else in the graph can tell, which is why this is the
     * generator's to work out rather than the author's to remember.
     */
    private function needsGuard(SagaBlueprint $blueprint, SagaStep $step): bool
    {
        if ($step->kind !== SagaStepKind::Transition) {
            return false;
        }

        foreach ($step->from as $place) {
            foreach ($blueprint->outgoing($place) as $sibling) {
                if ($sibling->kind !== SagaStepKind::Transition) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- the helpers

    /**
     * The helpers a Call needs when it cannot be written out in full.
     *
     * A child saga that can be built with no arguments is built inline; one
     * that needs anything else is resolved by a helper, and never by a
     * constructor parameter. A parameter would have to be regenerated as soon
     * as a second Call appeared, which puts it in the rewritten region — and
     * the dependency the author wired into it would go with it.
     *
     * @param  list<BlueprintIssue>  $issues
     * @return list<array{marker: string, member: string, code: string}>
     */
    private function helperStubs(SagaBlueprint $blueprint, array &$issues): array
    {
        $stubs = [];
        $parent = self::shortName($blueprint->subject());

        foreach ($blueprint->steps() as $step) {
            if ($step->kind !== SagaStepKind::Call) {
                continue;
            }

            $child = $step->childSaga;
            $childShort = self::shortName((string) $child);
            // The subject helper builds the CHILD's subject out of the parent's.
            // Its return type is the child subject, never the child saga — the
            // two are separate classes, and only one of them is what a Call's
            // `subject:` closure is allowed to return.
            $subjectShort = self::shortName((string) $step->childSubject);
            $reflection = self::classExists($child) ? new ReflectionClass((string) $child) : null;

            // An interface is not a child that failed to be a class. It is a
            // contract the container resolves, and `app()` is the honest stub for
            // it — so only a class that exists and still cannot be built is
            // reported here.
            if ($reflection !== null && ! $reflection->isInterface() && ! $reflection->isInstantiable()) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'Call "%s" runs %s, which cannot be instantiated — it is abstract, or its '
                    . 'constructor is not public. A Call holds the child saga as an object, so no '
                    . 'helper can produce one either.',
                    $step->name,
                    (string) $child,
                ));
            }

            if (! self::isConstructible($child)) {
                $stubs[] = [
                    'marker' => sprintf('@saga:stub:child:%s', $step->name),
                    'member' => sprintf('%sChild', $step->name),
                    'code' => implode("\n", $reflection !== null
                        ? [
                            sprintf('    // @saga:stub:child:%s', $step->name),
                            '    /**',
                            sprintf('     * @todo return the child saga "%s" runs — from the container, or built here.', $step->name),
                            '     */',
                            sprintf('    private function %sChild(): %s', $step->name, $childShort),
                            '    {',
                            sprintf('        return app(%s::class);', $childShort),
                            '    }',
                        ]
                        : [
                            sprintf('    // @saga:stub:child:%s', $step->name),
                            '    /**',
                            sprintf('     * @todo %s does not exist yet. Build it first: php artisan make:saga %s', (string) $child, $childShort),
                            '     */',
                            sprintf('    private function %sChild(): %s', $step->name, $childShort),
                            '    {',
                            sprintf("        throw new \\LogicException('Call \"%s\" has no child saga to run yet.');", $step->name),
                            '    }',
                        ]),
                ];
            }

            if (! $step->sharesSubjectWithChild()) {
                $stubs[] = [
                    'marker' => sprintf('@saga:stub:subject:%s', $step->name),
                    'member' => sprintf('%sSubject', $step->name),
                    'code' => implode("\n", [
                        sprintf('    // @saga:stub:subject:%s', $step->name),
                        '    /**',
                        sprintf('     * @todo build the subject "%s" runs on, out of this saga\'s.', $step->name),
                        '     */',
                        sprintf('    private static function %sSubject(%s $subject): %s', $step->name, $parent, $subjectShort),
                        '    {',
                        sprintf("        throw new \\LogicException('Call \"%s\" has no subject mapping yet.');", $step->name),
                        '    }',
                    ]),
                ];
            }
        }

        return $stubs;
    }

    // ---------------------------------------------------------------- files written once

    /**
     * The DTOs a graph names but does not yet have.
     *
     * Both are written only when the class does not exist, and never rewritten
     * afterwards: a payload is a class the author adds fields to, and a subject
     * is the saga's own state. A class outside the saga's namespace is reported
     * instead of written — the generator writes files next to the saga, and a
     * file declaring this namespace while being named for another would not
     * load.
     *
     * A name that belongs to a file this same plan writes is refused outright.
     * The plan is written in order and overwrites what it finds, so a payload
     * DTO called `OrderSaga` would replace the saga class — in the run that
     * created it, reported as `updated`.
     *
     * `$onDisk` is the target directory as it is now. It is here for the case
     * the class check cannot see: a file of that name is already there while
     * the class is not loadable, which means somebody wrote that file and
     * something else about it is wrong. See {@see dtoConflict()}.
     *
     * @param  array<string, string>  $onDisk
     * @param  list<BlueprintIssue>  $issues
     * @return list<GeneratedFile>
     */
    private function dtoFiles(SagaBlueprint $blueprint, string $namespace, array $onDisk, array &$issues): array
    {
        $files = [];
        $seen = [];

        // The two names no DTO may take, whatever the graph says. The command
        // writes both of them in every run, before any DTO, so the second write
        // to a shared path is always the DTO's.
        $reserved = [
            self::shortName($blueprint->saga()) => 'the saga itself',
            self::shortName($blueprint->saga()) . 'Listeners' => 'the saga\'s own listeners',
        ];

        $subject = $blueprint->subject();

        if (! self::classExists($subject)) {
            if (self::namespaceOf($subject) === $namespace && isset($reserved[self::shortName($subject)])) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'The subject of %s is %s, which is %s. No subject is written for it: that class\'s '
                    . 'file is written by this same run, and a subject stub landing there would '
                    . 'replace it. Give the saga a subject of its own.',
                    $blueprint->saga(),
                    $subject,
                    $reserved[self::shortName($subject)],
                ));
            } elseif (self::namespaceOf($subject) === $namespace) {
                $contents = $this->stub('saga.subject.php', [
                    'namespace' => $namespace,
                    'class' => self::shortName($subject),
                    'saga' => $blueprint->saga(),
                ]);

                $conflict = self::dtoConflict($onDisk, self::shortName($subject), $subject, $contents);

                if ($conflict === null) {
                    $seen[self::shortName($subject)] = true;

                    $files[] = new GeneratedFile(self::shortName($subject) . '.php', $contents);
                } else {
                    $issues[] = BlueprintIssue::error($conflict);
                }
            } else {
                $issues[] = BlueprintIssue::warning(sprintf(
                    'The subject %s does not exist, and it is not in %s, so nothing was generated for '
                    . 'it. A saga cannot be written without one.',
                    $subject,
                    $namespace,
                ));
            }
        }

        foreach ($blueprint->steps() as $step) {
            $awaits = $step->awaits;

            if ($step->kind !== SagaStepKind::Signal || $awaits === null || self::classExists($awaits)) {
                continue;
            }

            if (self::namespaceOf($awaits) !== $namespace) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'Signal "%s" awaits %s, which does not exist and is not in %s. The generator writes '
                    . 'next to the saga, so a class named for another namespace has to be created by '
                    . 'hand — and it has to exist, or the signal can never be delivered.',
                    $step->name,
                    $awaits,
                    $namespace,
                ));

                continue;
            }

            $short = self::shortName($awaits);

            if (isset($reserved[$short])) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'Signal "%s" awaits %s, which is %s. No payload is written for it: that class\'s '
                    . 'file is written by this same run, and a payload stub landing there would '
                    . 'replace it. A signal has to await a class of its own.',
                    $step->name,
                    $awaits,
                    $reserved[$short],
                ));

                continue;
            }

            if (isset($seen[$short])) {
                continue;
            }

            $seen[$short] = true;

            // The class is coming, and the graph is about to be told so: a
            // Signal awaiting a class that does not exist is an error, and it
            // stays one everywhere except for the run that writes it. It is
            // said here rather than after the conflict check so that a file in
            // the way is reported once, in its own words, instead of as a
            // missing class as well.
            $blueprint->promise($awaits);

            $contents = $this->stub('saga.payload.php', [
                'namespace' => $namespace,
                'class' => $short,
                'step' => $step->name,
                'saga' => $blueprint->saga(),
            ]);

            $conflict = self::dtoConflict($onDisk, $short, $awaits, $contents);

            if ($conflict !== null) {
                $issues[] = BlueprintIssue::error($conflict);

                continue;
            }

            $files[] = new GeneratedFile($short . '.php', $contents);
        }

        return $files;
    }

    /**
     * What to say when a DTO's file is already in the directory, or null when
     * the file may be written.
     *
     * A DTO is the one kind of file the command writes exactly once and never
     * again, so "the class does not exist" is not on its own a reason to write:
     * the question the class check cannot answer is whether a file of that name
     * is already sitting there. If it is, someone wrote it, and the class not
     * loading is a fact about *their* file — a rename, a class named something
     * else inside it, a directory outside the autoloader. Rendering the stub
     * over it would destroy their work on a run that looks entirely routine.
     *
     * The same contents are not a conflict: that is what a previous run of this
     * command left, and it comes back into the plan so that the write can say
     * `unchanged` rather than nothing at all.
     *
     * @param  array<string, string>  $onDisk
     */
    private static function dtoConflict(array $onDisk, string $short, string $class, string $contents): ?string
    {
        $name = $short . '.php';

        if (! isset($onDisk[$name]) || $onDisk[$name] === $contents) {
            return null;
        }

        return sprintf(
            'A file named %s is already in the target directory, and the class it has to declare, %s, '
            . 'does not load. This command writes that file once and never again, so it will not write '
            . 'over what is there: make the file declare the class, or move it aside.',
            $name,
            $class,
        );
    }

    // ---------------------------------------------------------------- the file on disk

    /**
     * Keep the author's half of a generated file and rewrite the rest.
     *
     * Both files are the same shape: a head the blueprint owns, down to the
     * marker that opens the author's region, and everything from that marker to
     * the end of the file belonging to whoever opened it last. The fresh
     * rendering supplies the head; the file on disk supplies the rest, verbatim,
     * marker line and all.
     *
     * @param  list<BlueprintIssue>  $issues
     */
    private function regenerateDownTo(string $fresh, string $existing, string $marker, array &$issues): string
    {
        $freshSpan = self::markerSpan($fresh, $marker);
        $span = self::markerSpan($existing, $marker);

        if ($freshSpan === null || $span === null) {
            $issues[] = BlueprintIssue::error(sprintf(
                'This file no longer has its "%s" marker, so there is no way to tell the generated '
                . 'part from yours without guessing. Put the marker back, or delete the file and let '
                . 'the command write a new one.',
                $marker,
            ));

            return $existing;
        }

        return substr($fresh, 0, $freshSpan[0]) . substr($existing, $span[0]);
    }

    /**
     * The half of an existing file the generator owns: everything above the
     * marker that opens the author's region.
     *
     * Only this half is read back for hand-written imports. A `use` in the class
     * body is a trait, not an import — and hoisting it above the class would
     * change it into one, aliasing the trait to a global class that does not
     * exist. The region below the marker is preserved verbatim and never needs
     * to be read for this at all.
     *
     * A file that has lost its marker is returned whole: nothing is regenerated
     * from the imports in that case, so what is read back is only ever asked for
     * so it can be reported.
     */
    private static function headOf(?string $existing, string $marker): ?string
    {
        if ($existing === null) {
            return null;
        }

        $span = self::markerSpan($existing, $marker);

        return $span === null ? $existing : substr($existing, 0, $span[0]);
    }

    /**
     * Add the stubs that are not in the file yet, and touch nothing else.
     *
     * "Already there" is the stub's own marker appearing anywhere in the file,
     * so a stub is written exactly once and the author's body inside it is
     * never rewritten. Deleting a marker by hand does not delete the stub: it
     * makes the next run add a second one, which is why the listeners are a map
     * keyed by event name — a duplicate key collapses, and an action cannot run
     * twice for a step that can only be compensated once.
     *
     * The class file cannot be given the same answer. There a stub declares a
     * method, a second one of that name is a file that will not compile, and the
     * author's body was the only thing the marker protected. So a stub that says
     * which member it declares is checked against the file as well, and one that
     * would collide is reported rather than added.
     *
     * @param  list<array{marker: string, member?: string, code: string}>  $stubs
     * @param  list<BlueprintIssue>  $issues
     */
    private function appendStubs(string $source, string $endMarker, array $stubs, array &$issues): string
    {
        $missing = [];

        foreach ($stubs as $stub) {
            if (self::hasMarker($source, $stub['marker'])) {
                continue;
            }

            $member = $stub['member'] ?? null;

            if ($member !== null && self::declaresMember($source, $member)) {
                $issues[] = BlueprintIssue::warning(sprintf(
                    'This file already declares %s(), and the stub that would add it has no "%s" '
                    . 'marker, so nothing was added — a second declaration would not compile. Put '
                    . 'the marker back above the method if the marker is what went missing.',
                    $member,
                    $stub['marker'],
                ));

                continue;
            }

            $missing[] = $stub['code'];
        }

        if ($missing === []) {
            return $source;
        }

        $span = self::markerSpan($source, $endMarker);

        if ($span === null) {
            $issues[] = BlueprintIssue::error(sprintf(
                'This file no longer has its "%s" marker, so there is nowhere to add the steps that '
                . 'are missing from it. Put the marker back, or delete the file and let the command '
                . 'write a new one.',
                $endMarker,
            ));

            return $source;
        }

        // The seam is normalized rather than measured: whatever blank lines sat
        // above the marker are replaced by exactly one, so inserting twice into
        // the same file does not grow the gap each time.
        return rtrim(substr($source, 0, $span[0]), "\n")
            . "\n\n" . implode("\n\n", $missing) . "\n\n"
            . substr($source, $span[0]);
    }

    /**
     * Whether the source already declares this method.
     *
     * The `function` keyword is required, so a call to the method — which is
     * what `definition()` is full of — is not mistaken for a declaration of it.
     */
    private static function declaresMember(string $source, string $member): bool
    {
        return preg_match('~\bfunction\s+' . preg_quote($member, '~') . '\s*\(~', $source) === 1;
    }

    /**
     * Whether the file still holds what the blueprint in it was written with.
     *
     * The hash stored beside the graph is the whole point of storing it: the
     * region on disk still hashing to it means nothing has been edited since
     * the last write, so whatever the graph says now may be rendered over it.
     *
     * A region that hashes to neither the stored hash nor the current rendering
     * is a hand edit — the only record of an intention the graph does not have
     * — and it is reported with a diff of the two, so nothing is written. The
     * one way past it is `--force`, which says the edit is meant to be lost.
     *
     * A block with no hash at all was written by hand, and then only the
     * rendering is left to compare against: the graph is still the source of
     * truth, and the file is still not to be overwritten unnoticed.
     *
     * @param  list<BlueprintIssue>  $issues
     */
    private function reportDrift(string $existing, string $rendered, bool $force, array &$issues): void
    {
        try {
            $previous = SagaBlueprint::fromSource($existing);
        } catch (JsonException|SagaException $e) {
            $issues[] = BlueprintIssue::error(sprintf(
                'The blueprint in this file cannot be read back (%s), so there is no way to tell '
                . 'whether rewriting it would lose an edit. Repair the block, or delete it to declare '
                . 'the file hand-written.',
                $e->getMessage(),
            ));

            return;
        }

        if ($previous === null) {
            return;
        }

        $renderer = $previous->renderer();

        if ($renderer !== null && $renderer !== SagaBlueprint::RENDERER_VERSION) {
            $issues[] = BlueprintIssue::warning(sprintf(
                'This file was written by generator v%d, and this is v%d. If the difference below is '
                . 'only formatting, the generator is what changed — not you.',
                $renderer,
                SagaBlueprint::RENDERER_VERSION,
            ));
        }

        $actual = self::definitionRegion($existing);

        if ($actual === null) {
            $issues[] = BlueprintIssue::error(sprintf(
                'This file no longer has its "%s" marker, so a hand edit to definition() cannot be '
                . 'told apart from the generated text. Put the marker back, or delete the file and '
                . 'let the command write a new one.',
                self::DEFINITION_START,
            ));

            return;
        }

        $actualHash = self::hash($actual);

        // Two ways the region can be nobody's edit, and both of them mean the
        // write is free. It can be what the last run wrote there — the graph has
        // moved on since, and re-rendering the method from it is the whole
        // point. Or it can already be what the graph renders now, which is an
        // author who changed the blueprint and the method together: the
        // rewritten region is what is already on disk, so nothing is lost by
        // writing it, and the refusal below would otherwise be permanent — the
        // stored hash only moves when a write succeeds, and the refusal is
        // exactly what stops one.
        if ($actualHash === $previous->bodyHash() || $actualHash === self::hash($rendered)) {
            return;
        }

        if ($force) {
            $issues[] = BlueprintIssue::warning(
                'definition() in this file was edited by hand, and --force overwrote that edit.',
            );

            return;
        }

        $issues[] = BlueprintIssue::error(sprintf(
            'definition() in this file matches neither its blueprint nor what the last run wrote '
            . "there, so it was edited by hand — and the blueprint is the source of truth. Rewriting "
            . "it would lose that edit. Move the change into the blueprint, or re-run with --force to "
            . "lose it deliberately.\n%s",
            $this->diff($rendered, $actual),
        ));
    }

    /**
     * The stubs left behind by steps this graph no longer has.
     *
     * Reported rather than removed, always: a stub's body is the author's work,
     * and the command has no way to tell a step it forgot from a listener that
     * was never about a step at all.
     *
     * @param  list<BlueprintIssue>  $issues
     */
    private function reportOrphans(
        ?string $saga,
        ?string $listeners,
        SagaBlueprint $blueprint,
        array &$issues,
    ): void {
        $steps = [];

        foreach ($blueprint->steps() as $step) {
            $steps[$step->name] = true;
        }

        $reported = [];

        foreach ([$saga, $listeners] as $source) {
            if ($source === null) {
                continue;
            }

            $found = preg_match_all(self::STUB_PATTERN, $source, $matches, PREG_SET_ORDER);

            if ($found === false || $found === 0) {
                continue;
            }

            foreach ($matches as $match) {
                if (isset($steps[$match[2]]) || isset($reported[$match[0]])) {
                    continue;
                }

                $reported[$match[0]] = true;

                $issues[] = BlueprintIssue::warning(sprintf(
                    'The stub marked "%s" belongs to a step this graph no longer has. The command '
                    . 'never deletes code, so remove it by hand once you are sure nothing needs it.',
                    $match[0],
                ));
            }
        }
    }

    // ---------------------------------------------------------------- rendering pieces

    /**
     * @param  list<string>  $places
     */
    private static function places(array $places): string
    {
        if ($places === []) {
            return '[]';
        }

        if (count($places) === 1) {
            return "'" . $places[0] . "'";
        }

        return '[' . implode(', ', array_map(
            static fn (string $place): string => "'" . $place . "'",
            $places,
        )) . ']';
    }

    private static function indent(string $text): string
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $lines[] = $line === '' ? '' : '    ' . $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The `use` block of a generated file.
     *
     * The generated classes are sorted and the author's are appended in the
     * order they were written, and the two are kept apart because they are not
     * the same kind of thing: one is a consequence of the graph and is rewritten
     * freely, the other is a line someone typed and would only notice losing at
     * the next request. So the second is carried forward verbatim.
     *
     * "Carried forward" is what makes this the only merge in the generator, and
     * it is a merge by alias, which is the same thing PHP resolves an import
     * by. An alias the generated set already claims is dropped rather than
     * duplicated, and one that would collide with the file's own class is an
     * error — because the alternative is a file that cannot be loaded.
     *
     * `$head` is the part of the file above the marker that opens the author's
     * region — see {@see headOf()} — so that what it carries forward is a `use`
     * statement and not a trait.
     *
     * @param  list<string>  $classes
     * @param  list<BlueprintIssue>  $issues
     */
    private function importBlock(
        array $classes,
        string $namespace,
        string $fileClass,
        ?string $head,
        array &$issues,
    ): string {
        /** @var array<string, string> $imports alias to FQCN */
        $imports = [];

        foreach (array_unique($classes) as $class) {
            if (self::namespaceOf($class) === $namespace) {
                continue;   // nothing to import from the namespace it is already in
            }

            $short = self::shortName($class);

            if ($short === $fileClass) {
                $issues[] = BlueprintIssue::error(sprintf(
                    '%s is named %s, which is the name of this file\'s own class, so it cannot be '
                    . 'imported into it. Rename one of the two, or move it.',
                    $class,
                    $short,
                ));

                continue;
            }

            if (isset($imports[$short])) {
                // Two classes, one name. PHP would take the last and the rest
                // of the file would silently refer to it, so the file is not
                // written at all — a graph naming both is a graph that cannot
                // be expressed in one namespace.
                $issues[] = BlueprintIssue::error(sprintf(
                    'Both %s and %s would be imported as %s, and no file can hold both. Alias one of '
                    . 'them, or rename it.',
                    $imports[$short],
                    $class,
                    $short,
                ));

                continue;
            }

            $imports[$short] = $class;
        }

        $lines = [];
        $statements = [];

        foreach ($imports as $class) {
            $line = 'use ' . $class . ';';
            $statements[$line] = true;
            $lines[] = $line;
        }

        sort($lines);

        foreach (self::handWrittenImports($head, $imports, $statements, $fileClass, $issues) as $line) {
            $lines[] = $line;
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n\n";
    }

    /**
     * The `use` statements the head of the file on disk has, and the generated
     * block does not.
     *
     * Matched by alias rather than by class, because the alias is what the rest
     * of the file actually refers to. A closure's `use (...)` is excluded by
     * requiring the next character not to be a bracket, and everything up to the
     * semicolon is kept as written — `use function`, `use const` and `as` are
     * all valid imports and none of them is worth reprinting.
     *
     * The caller passes only the head, and the statement has to start at the
     * beginning of its line. An import is neither nested nor indented, so a line
     * that is either belongs to the class body — a trait's `use` — and must not
     * be carried above the class, where it would mean something else.
     *
     * @param  array<string, string>  $imports  alias to FQCN, mutated in place
     * @param  array<string, true>  $statements  the generated statements verbatim
     * @param  list<BlueprintIssue>  $issues
     * @return list<string>
     */
    private static function handWrittenImports(
        ?string $head,
        array &$imports,
        array $statements,
        string $fileClass,
        array &$issues,
    ): array {
        if ($head === null) {
            return [];
        }

        $found = preg_match_all('~^use[ \t]+(?![(])([^;]+);~m', $head, $matches);

        if ($found === false || $found === 0) {
            return [];
        }

        $lines = [];

        foreach ($matches[1] as $statement) {
            $statement = trim($statement);
            $line = 'use ' . $statement . ';';

            if ($statement === '' || isset($statements[$line])) {
                continue;
            }

            $alias = self::alias($statement);

            if ($alias === $fileClass) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'This file imports %s as %s, which is the name of the class it declares. Remove '
                    . 'that import, or rename the class.',
                    $statement,
                    $alias,
                ));

                continue;
            }

            if (isset($imports[$alias])) {
                // One class, written twice, is not a collision but a duplicate.
                // `use \Symfony\Component\Workflow\Transition;` is how a
                // fully-qualified name is habitually typed, and the generated
                // block holds the same class without the backslash — so the
                // author's line is dropped and the file means what it meant.
                if (self::classOf($statement) === self::classOf($imports[$alias])) {
                    continue;
                }

                $issues[] = BlueprintIssue::error(sprintf(
                    'This file imports %s as %s, and the graph already imports %s as the same name. '
                    . 'No file can hold both — alias one of them, or rename it.',
                    $statement,
                    $alias,
                    $imports[$alias],
                ));

                continue;
            }

            $imports[$alias] = $statement;
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The name an import introduces into the file: its `as` alias, or the last
     * segment of the class it names.
     */
    private static function alias(string $statement): string
    {
        if (preg_match('~^(.+?)\s+as\s+(\w+)$~i', $statement, $matches) === 1) {
            return $matches[2];
        }

        return self::shortName($statement);
    }

    /**
     * The class an import statement names, however it was spelled there.
     *
     * `use \Foo\Bar;`, `use Foo\Bar;` and `use Foo\Bar as Baz;` all name one
     * class, and the leading backslash — the way a fully-qualified name is
     * habitually written — is a difference in spelling rather than in meaning.
     * Comparing two imports means comparing this, not the text.
     */
    private static function classOf(string $statement): string
    {
        if (preg_match('~^(.+?)\s+as\s+\w+$~i', $statement, $matches) === 1) {
            return ltrim(trim($matches[1]), '\\');
        }

        return ltrim($statement, '\\');
    }

    /**
     * A stub template, with its placeholders filled.
     *
     * A placeholder left unfilled is an error rather than an empty string: it
     * means a template and this method disagree, and the file that would come
     * out of it would carry a literal `{{ class }}` into someone's application.
     *
     * @param  array<string, string>  $variables
     *
     * @throws SagaException when a template is missing or a placeholder is unfilled
     */
    private function stub(string $name, array $variables): string
    {
        $path = __DIR__ . '/../../../database/stubs/' . $name . '.stub';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new SagaException(sprintf(
                'The generator cannot read its own template at %s. The package is incomplete.',
                $path,
            ));
        }

        foreach ($variables as $key => $value) {
            $contents = str_replace('{{ ' . $key . ' }}', $value, $contents);
        }

        if (str_contains($contents, '{{')) {
            throw new SagaException(sprintf(
                'The template %s still holds a placeholder the generator does not fill. This is a bug '
                . 'in the package, not in the saga being built.',
                $path,
            ));
        }

        return $contents;
    }

    // ---------------------------------------------------------------- text

    /**
     * Where a marker's line begins and where its text ends.
     *
     * A whole line, and only that: the docblock of a generated class quotes its
     * own markers in prose, and a search for the text alone would find those
     * first. Returns `[start of the line, end of its text]`, so a caller can
     * keep the marker and the indentation around it.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function markerSpan(string $source, string $marker, int $from = 0): ?array
    {
        $offset = $from;

        while (($position = strpos($source, $marker, $offset)) !== false) {
            $lineStart = strrpos(substr($source, 0, $position), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;

            $lineEnd = strpos($source, "\n", $position);
            $lineEnd = $lineEnd === false ? strlen($source) : $lineEnd;

            if (trim(substr($source, $lineStart, $lineEnd - $lineStart)) === $marker) {
                return [$lineStart, $lineEnd];
            }

            $offset = $position + 1;
        }

        return null;
    }

    /**
     * Whether a stub's marker is already in the file.
     *
     * A plain `str_contains` would do for every name but one kind: `charge` and
     * `charge_card` are both legal step names, and a substring test finds the
     * first inside the second. So the marker only counts when nothing
     * name-shaped follows it — which is what keeps two steps whose names share
     * a prefix from quietly sharing one stub.
     */
    private static function hasMarker(string $source, string $marker): bool
    {
        return preg_match('~' . preg_quote($marker, '~') . '(?![a-z0-9_])~', $source) === 1;
    }

    private static function definitionRegion(string $source): ?string
    {
        $start = self::markerSpan($source, self::DEFINITION_START);

        if ($start === null) {
            return null;
        }

        $end = self::markerSpan($source, self::DEFINITION_END, $start[1]);

        if ($end === null) {
            return null;
        }

        return substr($source, $start[1], $end[0] - $start[1]);
    }

    /**
     * The number stored beside the graph, and the one the file is checked with.
     *
     * Trailing whitespace and blank lines at either end are normalized away on
     * purpose: this hash exists to notice an edit that changed what the graph
     * means, and an editor that trims a line is not one.
     */
    private static function hash(string $region): string
    {
        $lines = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $region)) as $line) {
            $lines[] = rtrim($line);
        }

        return 'sha256:' . hash('sha256', trim(implode("\n", $lines)));
    }

    /**
     * What the blueprint would write that the file does not have, and the other
     * way round.
     *
     * A set difference by line rather than a positional one: a hand edit is
     * usually an added or a removed line, which shifts everything below it and
     * would turn a positional diff into noise. Order within each half is kept,
     * so the two lists still read like the file they came from.
     */
    private function diff(string $expected, string $actual): string
    {
        $expectedLines = array_map(trim(...), explode("\n", $expected));
        $actualLines = array_map(trim(...), explode("\n", $actual));
        $lines = [];

        foreach ($expectedLines as $line) {
            if ($line !== '' && ! in_array($line, $actualLines, true)) {
                $lines[] = '  the blueprint says: ' . $line;
            }
        }

        foreach ($actualLines as $line) {
            if ($line !== '' && ! in_array($line, $expectedLines, true)) {
                $lines[] = '  the file says:      ' . $line;
            }
        }

        if ($lines === []) {
            return '  (the same lines, in a different order or spacing)';
        }

        if (count($lines) > 24) {
            $lines = array_slice($lines, 0, 24);
            $lines[] = '  ...';
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------- names

    /**
     * The namespace a fully qualified name lives in.
     *
     * The leading backslash is dropped first, and that is not cosmetic: a name
     * written `\App\Sagas\OrderSaga` is the same class as one written without,
     * but everything here compares and pastes the result into a class body. A
     * namespace left as `\App\Sagas` renders `namespace \App\Sagas;`, which is
     * not PHP — and the same un-normalized string would make a payload in the
     * saga's own namespace look like a foreign one, so the author would be
     * refused for a class the wizard had just promised to write.
     * `SagaWizard::namespaceOf()` asks the question the same way.
     */
    private static function namespaceOf(string $class): string
    {
        $class = ltrim($class, '\\');
        $separator = strrpos($class, '\\');

        return $separator === false ? '' : substr($class, 0, $separator);
    }

    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * The same question `SagaBlueprint` asks, stated so that a caller learns
     * what the answer means: a name that exists is a `class-string`, and that is
     * what `ReflectionClass` takes. `class_exists()` alone narrows nothing when
     * it is called from inside another method, so the narrowing is declared.
     *
     * An interface counts, and that is not a detail. `Signal::accepts()` is an
     * `instanceof`, so a contract is a legitimate thing for a step to name, and
     * the wizard and the blueprint both accept one. Answering `class_exists()`
     * here would disagree with them: a signal awaiting an interface would look
     * like a signal awaiting a class nobody has written — refused for being in
     * another namespace, or, in the saga's own, overwritten with a generated
     * class of the same name.
     *
     * @phpstan-assert-if-true class-string $class
     */
    private static function classExists(?string $class): bool
    {
        return $class !== null && (class_exists($class) || interface_exists($class));
    }

    /**
     * Whether the child saga can be built where it is named, with no arguments.
     *
     * The alternative is a helper the author fills in, and it exists for the
     * cases a generator must not guess at: a child the container wires, and a
     * child that does not exist yet. An abstract child is neither — it is
     * reported by {@see helperStubs()}, because no helper can produce one.
     */
    private static function isConstructible(?string $class): bool
    {
        if (! self::classExists($class)) {
            return false;
        }

        $reflection = new ReflectionClass((string) $class);

        if (! $reflection->isInstantiable()) {
            return false;
        }

        $constructor = $reflection->getConstructor();

        return $constructor === null || $constructor->getNumberOfRequiredParameters() === 0;
    }
}
