<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

use JsonException;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Dumper\MermaidDumper;
use Symfony\Component\Workflow\Transition;
use Techork\Saga\Saga;
use Techork\Saga\SagaException;
use Techork\Saga\Signal;

use function array_map;
use function class_exists;
use function explode;
use function implode;
use function in_array;
use function interface_exists;
use function is_array;
use function is_string;
use function is_subclass_of;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_contains;
use function strrpos;
use function substr;

/**
 * The graph of a saga, as data — the thing the wizard edits and the file
 * generation is derived from.
 *
 * It is deliberately free of any Illuminate import, like {@see SagaStep}: this
 * is a model of a saga, not a piece of the Laravel integration, and keeping it
 * that way is what lets every rule below be tested without a container, a
 * console or a filesystem.
 *
 * A blueprint is not authoritative on its own. It is stamped with the renderer
 * that produced the file it came from and the hash of the `definition()` body
 * that renderer wrote ({@see stamp()}), and it can be recovered from a
 * generated class by {@see fromSource()}. That pair is what lets a second run
 * tell "the author edited the graph by hand" from "nothing changed".
 */
final class SagaBlueprint
{
    /**
     * The shape of the JSON block itself. Raised when a field is added or its
     * meaning changes, so an older command can refuse a newer file instead of
     * silently dropping what it does not understand.
     */
    public const SPEC_VERSION = 1;

    /**
     * The generator's own output version. Raised when the rendering changes,
     * so a hash mismatch can be explained as "this file came from an older
     * renderer" before the author is asked to discard a hand edit.
     */
    public const RENDERER_VERSION = 1;

    public const DOCBLOCK_TAG = '@saga-blueprint';
    public const DOCBLOCK_END = '@saga-blueprint:end';

    /**
     * The JSON block, delimited by the tag and an explicit end marker.
     *
     * The end marker is not decoration: it is what makes the block extractable
     * without a JSON parser and without counting braces, and it is why the
     * block can be pretty-printed. A single-line block would be simpler to
     * read back and much worse to read, and this block is the source of truth
     * the author is told to edit.
     *
     * `\r?` before the final `$` is what lets the block be read back from a
     * file with CRLF line endings, which is every file on a Windows checkout.
     * `$` under `~m` is true immediately before a `\n` and at the end of the
     * subject, and nothing before it here may consume a `\r` — so without that
     * `\r?` the end marker could never match, and a file the command had
     * written perfectly would be refused as carrying no complete block.
     */
    private const BLOCK_PATTERN = '~^[ \t]*\*[ \t]*%s[ \t]*\R(?<json>(?:[ \t]*\*[^\r\n]*\R)*?)[ \t]*\*[ \t]*%s[ \t]*\r?$~m';

    /** @var list<string> */
    private array $places = [];

    /** @var list<string> */
    private array $initial = [];

    /** @var list<SagaStep> */
    private array $steps = [];

    private ?int $renderer = null;

    private ?string $bodyHash = null;

    /**
     * Classes this graph names that do not exist yet and are going to be
     * written for it. Transient, and deliberately not serialized: a blueprint
     * read back out of a file was written by a run that already generated them,
     * so a promise in the block would be a promise about the past.
     *
     * @var list<string>
     */
    private array $promised = [];

    /**
     * @param  string  $saga  the saga's FQCN — the name its `Workflow` must carry
     * @param  string  $subject  the subject DTO's FQCN
     *
     * @throws SagaException when either is not a plain class name
     */
    public function __construct(
        private readonly string $saga,
        private readonly string $subject,
    ) {
        self::assertClassName($saga, 'saga');
        self::assertClassName($subject, 'subject');
    }

    public function saga(): string
    {
        return $this->saga;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    /** @return list<string> */
    public function places(): array
    {
        return $this->places;
    }

    /** @return list<string> */
    public function initial(): array
    {
        return $this->initial;
    }

    /** @return list<SagaStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function renderer(): ?int
    {
        return $this->renderer;
    }

    public function bodyHash(): ?string
    {
        return $this->bodyHash;
    }

    /**
     * Record which renderer wrote this blueprint's file, and the hash of the
     * `definition()` body it wrote.
     *
     * Stamped by the scaffolder at write time and read back by
     * {@see fromSource()}; the wizard never sets it.
     */
    public function stamp(int $renderer, string $bodyHash): void
    {
        $this->renderer = $renderer;
        $this->bodyHash = $bodyHash;
    }

    /**
     * Record that a class this graph names is going to be written for it.
     *
     * The wizard is the only caller, and the only moment this is true: it asks
     * for the payload of a Signal, finds the class is not there, and asks the
     * author whether to generate it. If the answer is yes, the class is coming —
     * and the rule that would otherwise refuse the step, and refuse it forever,
     * is about exactly that. Saying so out loud is better than teaching
     * {@see validate()} to guess, and better than matching its messages.
     *
     * Nothing serializes this. A blueprint recovered from a file was written by
     * a run that generated what it named, so a promise carried forward would be
     * a promise about a past that has already happened.
     *
     * @throws SagaException when the name is not a plain fully-qualified class name
     */
    public function promise(string $class): void
    {
        self::assertClassName($class, 'promised class');

        if (! in_array($class, $this->promised, true)) {
            $this->promised[] = $class;
        }
    }

    public function isPromised(string $class): bool
    {
        return in_array($class, $this->promised, true);
    }

    /**
     * @throws SagaException when the name is not a lower-case identifier
     */
    public function addPlace(string $place): void
    {
        self::assertName($place);

        if (! in_array($place, $this->places, true)) {
            $this->places[] = $place;
        }
    }

    /**
     * Mark a place as one the saga starts in, declaring it if it is new.
     *
     * Declaring it here is what keeps "initial" and "places" from ever
     * disagreeing, so nothing downstream has to check that they do: a start
     * place is a place by construction, and it is always drawn.
     *
     * @throws SagaException when the name is not a lower-case identifier
     */
    public function addInitial(string $place): void
    {
        $this->addPlace($place);

        if (! in_array($place, $this->initial, true)) {
            $this->initial[] = $place;
        }
    }

    /**
     * Add an edge. It declares nothing.
     *
     * That is deliberate, and it is the whole reason a typo can be caught at
     * all. Symfony's own `Definition::addPlace()` invents any place a
     * transition names and promotes the first one to initial, so a misspelt
     * `from` is not an error at runtime — it is a new place, and if it is the
     * first one touched, the saga's starting place. Declaring places from the
     * arcs would reproduce exactly that failure inside the blueprint: the typo
     * would quietly become a place, and there would be nothing left to report.
     *
     * So `places` is the only source of the place set, and an arc endpoint is
     * checked against it by {@see validate()}. A place is declared by
     * {@see addPlace()} or {@see addInitial()} before any arc touches it.
     */
    public function addStep(SagaStep $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @return list<SagaStep>
     */
    public function outgoing(string $place): array
    {
        $out = [];

        foreach ($this->steps as $step) {
            if (in_array($place, $step->from, true)) {
                $out[] = $step;
            }
        }

        return $out;
    }

    /**
     * @return list<SagaStep>
     */
    public function incoming(string $place): array
    {
        $in = [];

        foreach ($this->steps as $step) {
            if (in_array($place, $step->to, true)) {
                $in[] = $step;
            }
        }

        return $in;
    }

    /**
     * Whether everything leaving this place is a Signal or a Call.
     *
     * That is the whole definition of a parking place: the runner queues
     * ordinary transitions, so a place whose only exits are Signals is where
     * the saga waits, and nothing else declares it special.
     */
    public function isParkingPlace(string $place): bool
    {
        $outgoing = $this->outgoing($place);

        if ($outgoing === []) {
            return false;
        }

        foreach ($outgoing as $step) {
            if ($step->kind === SagaStepKind::Transition) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everything wrong with the graph, as errors and warnings.
     *
     * Errors mirror rules the runtime enforces or silently survives, and each
     * one is quoted where it comes from. Warnings are the judgements no
     * runtime can make — whether a guard will actually hold a mixed exit — and
     * the wizard shows them without refusing the step.
     *
     * The existence checks are stricter than the runtime on purpose. A
     * Signal whose awaited class does not exist is not an error anywhere
     * else: `accepts()` is an `instanceof` against a string, so it is simply
     * false forever, the saga parks where nothing can ever reach it, and the
     * only symptom arrives weeks later as a `SagaNotWaitingException` raised
     * by whoever finally tries to signal it. The wizard is the one place where
     * catching that costs nothing.
     *
     * @return list<BlueprintIssue>
     */
    public function validate(): array
    {
        $issues = [];

        foreach ($this->structuralIssues() as $issue) {
            $issues[] = $issue;
        }

        foreach ($this->classIssues() as $issue) {
            $issues[] = $issue;
        }

        foreach ($this->advice() as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * @return list<BlueprintIssue>
     */
    private function structuralIssues(): array
    {
        $issues = [];

        if ($this->places === []) {
            $issues[] = BlueprintIssue::error('The saga has no places yet, so there is nothing to fire.');
        }

        if ($this->initial === []) {
            $issues[] = BlueprintIssue::error(
                'The saga has no initial place. A saga starts by marking something, and without it '
                . 'the definition has nowhere to begin.',
            );
        }

        $seen = [];

        foreach ($this->steps as $step) {
            if (isset($seen[$step->name])) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'Transition "%s" is declared more than once. Symfony applies every matching '
                    . 'transition, so its action would run once per arc while compensation could only '
                    . 'undo it once — start() refuses this outright. Give each arc a distinct name.',
                    $step->name,
                ));
            }

            $seen[$step->name] = true;

            foreach ([...$step->from, ...$step->to] as $place) {
                if (! in_array($place, $this->places, true)) {
                    $issues[] = BlueprintIssue::error(sprintf(
                        'Transition "%s" touches the place "%s", which the graph does not declare. '
                        . 'Symfony would invent it silently — and if it is the first place touched, '
                        . 'it would become the saga\'s initial marking.',
                        $step->name,
                        $place,
                    ));
                }
            }
        }

        foreach ($this->initial as $place) {
            if (in_array($place, $this->places, true) && $this->outgoing($place) === []) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'The saga cannot start: its initial place "%s" has no outgoing transition at all, '
                    . 'so nothing can ever fire. This is a definition bug, and start() rejects it.',
                    $place,
                ));
            }
        }

        return $issues;
    }

    /**
     * @return list<BlueprintIssue>
     */
    private function classIssues(): array
    {
        $issues = [];

        foreach ($this->steps as $step) {
            $awaits = $step->awaits;

            if ($step->kind === SagaStepKind::Signal
                && $awaits !== null
                && ! self::classExists($awaits)
                && $this->isPromised($awaits)
            ) {
                // The class is not there yet and is being written for this very
                // step. Nothing else about the step changes, so this is the
                // whole of what a promise buys — and it is why the wizard can
                // accept a Signal whose payload it has just agreed to generate.
                continue;
            }

            if ($step->kind === SagaStepKind::Signal && ($awaits === null || ! self::classExists($awaits))) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'Signal "%s" awaits %s, which does not exist. Nothing would ever satisfy it: '
                    . 'Signal::accepts() is an instanceof against that name, so the saga would park '
                    . 'there permanently and signal() would later report that it is not waiting for it.',
                    $step->name,
                    $awaits ?? '(nothing)',
                ));
            }

            if ($step->kind !== SagaStepKind::Call) {
                continue;
            }

            $childSaga = $step->childSaga;

            if ($childSaga !== null && ! self::classExists($childSaga)) {
                // A warning, not an error: building the caller before the child
                // is the order people actually work in, and the step is
                // representable either way — it is rendered as a helper that
                // throws until the child exists. Refusing it would mean no saga
                // could ever be written first.
                $issues[] = BlueprintIssue::warning(sprintf(
                    'Call "%s" runs %s, which does not exist yet. The step is written in the form you '
                    . 'finish by hand — a helper that throws until the child is there — so run "php '
                    . 'artisan make:saga %s" first, or leave it for later.',
                    $step->name,
                    $childSaga,
                    self::shortName($childSaga),
                ));
            } elseif ($childSaga !== null && self::classExists($childSaga) && ! is_subclass_of($childSaga, Saga::class)) {
                $issues[] = BlueprintIssue::error(sprintf(
                    'Call "%s" runs %s, which exists but is not a %s. A Call holds the child saga as '
                    . 'an object, so this can never be constructed.',
                    $step->name,
                    $childSaga,
                    Saga::class,
                ));
            }

            $childSubject = $step->childSubject;

            if ($childSubject !== null && ! self::classExists($childSubject)) {
                $issues[] = BlueprintIssue::warning(sprintf(
                    'Call "%s" builds a %s for its child, and that class does not exist yet. Its '
                    . 'subject belongs to the child saga, so nothing here generates it.',
                    $step->name,
                    $childSubject,
                ));
            }
        }

        return $issues;
    }

    /**
     * @return list<BlueprintIssue>
     */
    private function advice(): array
    {
        $issues = [];

        foreach ($this->places as $place) {
            $outgoing = $this->outgoing($place);
            $hasSignal = false;
            $hasTransition = false;

            foreach ($outgoing as $step) {
                if ($step->kind === SagaStepKind::Transition) {
                    $hasTransition = true;
                } else {
                    $hasSignal = true;
                }
            }

            if ($hasSignal && $hasTransition) {
                $issues[] = BlueprintIssue::warning(sprintf(
                    'Place "%s" is left by both a Signal and an ordinary transition. The ordinary one '
                    . 'is queued as soon as the saga arrives, so unless a guard blocks it the saga '
                    . 'never waits here at all — that is the shape of a deadline, and the guard is '
                    . 'the part nothing can check for you.',
                    $place,
                ));
            }

            if ($outgoing === [] && $this->incoming($place) === []) {
                $issues[] = BlueprintIssue::warning(sprintf(
                    'Place "%s" has no incoming and no outgoing transition, so no token can ever '
                    . 'reach it.',
                    $place,
                ));
            }
        }

        $terminal = [];

        foreach ($this->places as $place) {
            if ($this->outgoing($place) === []) {
                $terminal[] = $place;
            }
        }

        if ($this->places !== [] && $terminal === []) {
            $issues[] = BlueprintIssue::warning(
                'No place in this graph is terminal: every one of them has a way out, so the saga '
                . 'never ends. That is legitimate for a saga that loops, and a mistake otherwise.',
            );
        }

        return $issues;
    }

    /**
     * The graph as a Symfony `Definition` — for inspection and for drawing.
     *
     * A Call is rendered as a `Signal` here, not as a `Call`. A `Call` holds an
     * instantiated child saga and a subject closure, neither of which a
     * blueprint can produce — it does not know how the application resolves
     * its sagas. It is the honest substitution: `Call` passes `$runs::class`
     * to its `Signal` parent for exactly the same purpose, and every
     * structural property that matters here — the arcs, the name, the places —
     * is identical. The definition this returns is never handed to a runner;
     * the generated class builds the real `Call`.
     */
    public function toDefinition(): Definition
    {
        $transitions = [];

        foreach ($this->steps as $step) {
            $transitions[] = match ($step->kind) {
                SagaStepKind::Transition => new Transition($step->name, $step->from, $step->to),
                SagaStepKind::Signal => new Signal($step->name, $step->from, $step->to, self::classString($step->awaits, $step, 'awaited type')),
                SagaStepKind::Call => new Signal($step->name, $step->from, $step->to, self::classString($step->childSaga, $step, 'child saga')),
            };
        }

        // The initial places are always passed explicitly. Left to itself,
        // Definition promotes the first place it is given and silently accepts
        // a marking that is not in the graph; saying it out loud is the only
        // way a mistake in it can be reported.
        return new Definition($this->places, $transitions, $this->initial);
    }

    /**
     * The graph as Mermaid.
     *
     * Delegated to Symfony's own dumper rather than drawn here, and that is
     * the reason `Signal` extends `Transition` instead of being a parallel
     * list beside the definition: the dumper walks `getTransitions()`, so
     * waits and calls appear as ordinary edges, in the same diagram as
     * everything else.
     */
    public function toMermaid(): string
    {
        $dumper = new MermaidDumper(
            MermaidDumper::TRANSITION_TYPE_WORKFLOW,
            MermaidDumper::DIRECTION_LEFT_TO_RIGHT,
        );

        return $dumper->dump($this->toDefinition());
    }

    /**
     * @throws JsonException never — encoding a plain array cannot fail
     */
    public function toJson(): string
    {
        $data = [
            'v' => self::SPEC_VERSION,
            'saga' => $this->saga,
            'subject' => $this->subject,
            'initial' => $this->initial,
            'places' => $this->places,
            'steps' => array_map(static fn (SagaStep $step): array => $step->toArray(), $this->steps),
        ];

        if ($this->renderer !== null) {
            $data['renderer'] = $this->renderer;
        }

        if ($this->bodyHash !== null) {
            $data['bodyHash'] = $this->bodyHash;
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The blueprint's lines as they sit inside a docblock: each already carries
     * the ` * ` the surrounding comment needs, and none carries the comment
     * delimiters themselves.
     *
     * Split out for the template that renders a generated class, which puts a
     * summary above the block and so cannot use the whole docblock.
     *
     * @return list<string>
     */
    public function toDocblockLines(): array
    {
        $lines = [' * ' . self::DOCBLOCK_TAG];

        foreach (explode("\n", $this->toJson()) as $line) {
            $lines[] = rtrim(' * ' . $line);
        }

        $lines[] = ' * ' . self::DOCBLOCK_END;

        return $lines;
    }

    /**
     * The blueprint as the docblock that carries it.
     *
     * The exact inverse of {@see fromSource()}, and deliberately next to it:
     * the two share the delimiter and the indentation, so a test can round-trip
     * a blueprint through a rendered class and back without either side of the
     * pair being a second, hand-written description of the format.
     */
    public function toDocblock(): string
    {
        return implode("\n", ['/**', ...$this->toDocblockLines(), ' */']);
    }

    /**
     * @throws JsonException when the payload is not JSON
     * @throws SagaException when it is JSON but not a blueprint
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new SagaException('The blueprint is not a JSON object.');
        }

        $version = $decoded['v'] ?? null;

        if ($version !== self::SPEC_VERSION) {
            throw new SagaException(sprintf(
                'This saga carries a version %s blueprint, and this command writes version %d. '
                . 'Upgrade the package rather than letting it rewrite a file it does not understand.',
                is_int($version) ? (string) $version : 'unknown',
                self::SPEC_VERSION,
            ));
        }

        $blueprint = new self(
            self::string($decoded, 'saga'),
            self::string($decoded, 'subject'),
        );

        foreach (self::stringList($decoded, 'places') as $place) {
            $blueprint->addPlace($place);
        }

        foreach (self::stringList($decoded, 'initial') as $place) {
            $blueprint->addInitial($place);
        }

        $steps = $decoded['steps'] ?? null;

        if (! is_array($steps)) {
            throw new SagaException("The blueprint's 'steps' must be a list.");
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                throw new SagaException("The blueprint's 'steps' must hold objects only.");
            }

            $blueprint->addStep(SagaStep::fromArray($step));
        }

        $renderer = $decoded['renderer'] ?? null;
        $bodyHash = $decoded['bodyHash'] ?? null;

        if (is_int($renderer) && is_string($bodyHash)) {
            $blueprint->stamp($renderer, $bodyHash);
        }

        return $blueprint;
    }

    /**
     * Recover the blueprint from a generated class's source, or null when the
     * file carries none.
     *
     * A file with no blueprint is simply not ours — a saga written by hand,
     * or a different class entirely — and null says so. A file whose blueprint
     * is present but unreadable THROWS instead, because the two cases must not
     * look alike to a caller about to overwrite the file: "nothing to carry
     * forward" is a licence to write, and "I cannot read what is there" is the
     * opposite. Reporting the second as the first would clobber a saga the
     * moment its JSON was damaged.
     *
     * That covers a block whose JSON does not parse, and equally a block the
     * author cut in half: an opening tag with no closing one is a damaged
     * blueprint, never an absent one, and it is refused for the same reason.
     *
     * @throws JsonException when the block is not JSON
     * @throws SagaException when a block is present but not a readable blueprint
     */
    public static function fromSource(string $source): ?self
    {
        $pattern = sprintf(
            self::BLOCK_PATTERN,
            preg_quote(self::DOCBLOCK_TAG, '~'),
            preg_quote(self::DOCBLOCK_END, '~'),
        );

        if (preg_match($pattern, $source, $matches) !== 1) {
            if (str_contains($source, self::DOCBLOCK_TAG)) {
                throw new SagaException(sprintf(
                    'This file carries a %s tag but no complete blueprint block: the closing %s is '
                    . 'missing, or the JSON is not sitting inside the comment. The file is not safe '
                    . 'to rewrite — recover the block, or delete the tag to declare the file hand-written.',
                    self::DOCBLOCK_TAG,
                    self::DOCBLOCK_END,
                ));
            }

            return null;
        }

        $lines = [];

        foreach (preg_split('/\R/', $matches['json']) ?: [] as $line) {
            $lines[] = preg_replace('~^[ \t]*\*[ \t]?~', '', $line) ?? $line;
        }

        return self::fromJson(implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new SagaException(sprintf(
            "The blueprint is missing the string field '%s'.",
            $key,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new SagaException(sprintf("The blueprint's '%s' must be a list.", $key));
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new SagaException(sprintf("The blueprint's '%s' must hold strings only.", $key));
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * A class name that has to be real, recovered as the class-string the
     * Symfony constructors require.
     *
     * `Signal::$awaits` is declared `class-string`, and that declaration is
     * worth honouring rather than working around: a name that does not resolve
     * cannot be drawn, matched or run, so rendering a diagram out of one would
     * be drawing an edge that does not exist. {@see validate()} reports the
     * same condition as an error before anything is rendered; this is the same
     * rule stated where the type system can hold it.
     *
     * @return class-string
     */
    private static function classString(?string $value, SagaStep $step, string $which): string
    {
        $class = $value ?? throw new SagaException(sprintf(
            'Transition "%s" has no %s, though its kind requires one.',
            $step->name,
            $which,
        ));

        if (! class_exists($class) && ! interface_exists($class)) {
            throw new SagaException(sprintf(
                'Transition "%s" names %s as its %s, and that class does not exist, so the graph '
                . 'cannot be built from it.',
                $step->name,
                $class,
                $which,
            ));
        }

        return $class;
    }

    private static function classExists(?string $class): bool
    {
        return $class !== null && (class_exists($class) || interface_exists($class));
    }

    /**
     * The name a class is known by in its own namespace — what `make:saga`
     * would be given to generate it.
     */
    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    private static function assertName(string $place): void
    {
        if (preg_match(SagaStep::NAME_PATTERN, $place) !== 1) {
            throw new SagaException(sprintf(
                "'%s' is not usable as a place name: places must match %s, so that a name can never "
                . 'carry a comment terminator or an expression into the blueprint docblock.',
                $place,
                SagaStep::NAME_PATTERN,
            ));
        }
    }

    private static function assertClassName(string $class, string $which): void
    {
        if (preg_match(SagaStep::CLASS_PATTERN, $class) !== 1) {
            throw new SagaException(sprintf(
                "'%s' cannot be used as the %s of a saga: expected a plain fully-qualified class "
                . 'name matching %s.',
                $class,
                $which,
                SagaStep::CLASS_PATTERN,
            ));
        }
    }
}
