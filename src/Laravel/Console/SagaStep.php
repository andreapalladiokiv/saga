<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

use Techork\Saga\SagaException;

use function array_column;
use function array_unique;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * One edge of a saga's graph, as the wizard collected it.
 *
 * This is the shape that survives in the blueprint docblock, so every field
 * that reaches it is constrained here rather than at the point of use. Two
 * kinds of check live apart on purpose:
 *
 *  - SHAPE is this constructor, and it THROWS. A place whose name carried a
 *    comment terminator, or a raw PHP expression, would either close the
 *    comment the blueprint lives in or need an expression validator nobody
 *    should write, so names are restricted to lower-case identifiers and class
 *    names to plain FQCNs. What cannot be represented is refused here and can
 *    never reach a file.
 *  - EXISTENCE is {@see SagaBlueprint::validate()}, and it REPORTS. A class
 *    that does not exist yet is representable — it is a blueprint for a saga
 *    someone is still building — so it is an issue to show, not an exception.
 *
 * `awaits` is the awaited payload of a `Signal` only. A `Call` derives its own
 * from the saga it runs ({@see \Techork\Saga\Call::__construct()}) and never
 * matches on it — {@see \Techork\Saga\Call::accepts()} is constant false — so
 * asking for one would be asking a question with no meaning. What a Call's
 * action stub actually needs is {@see $childSubject}, to type its
 * `Signal::payload()` call.
 */
final readonly class SagaStep
{
    /** Places and transitions: lower-case, so a name can never carry a comment terminator. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** A plain FQCN, optionally leading-backslashed. No expressions, no generics. */
    public const CLASS_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * Reserved by the runner for the markers it journals into history
     * ({@see \Techork\Saga\SagaRunner::ROLLBACK_FAILED}), and rejected at
     * `start()` — reported by name here so the reason is not just "bad regex".
     */
    public const RESERVED_PREFIX = '!saga:';

    /** @var list<string> */
    public array $from;

    /** @var list<string> */
    public array $to;

    /**
     * @param  list<string>  $from  where the step leaves; more than one is a join
     * @param  list<string>  $to  where it arrives; more than one is a fork
     * @param  string|null  $awaits  the payload type of a Signal — a class-string
     * @param  string|null  $childSaga  a Call's child saga — a class-string
     * @param  string|null  $childSubject  a Call's child subject — a class-string
     * @param  bool  $childSharesSubject  a Call may hand the parent's subject straight through
     *
     * @throws SagaException when the step cannot be represented at all
     */
    public function __construct(
        public SagaStepKind $kind,
        public string $name,
        array $from,
        array $to,
        public ?string $awaits = null,
        public ?string $childSaga = null,
        public ?string $childSubject = null,
        public bool $childSharesSubject = false,
    ) {
        $this->from = self::places($from, $name, 'from');
        $this->to = self::places($to, $name, 'to');

        self::assertName($name, 'a transition');

        if ($kind === SagaStepKind::Signal) {
            self::assertClassName($awaits, $name, 'awaited type');
        } elseif ($awaits !== null) {
            throw new SagaException(sprintf(
                "Transition '%s' is a %s and declares an awaited type. Only a Signal awaits a payload: "
                . 'a Call derives its own from the saga it runs, and an ordinary transition is never '
                . 'fired by signal() at all.',
                $name,
                $kind->value,
            ));
        }

        if ($kind === SagaStepKind::Call) {
            self::assertClassName($childSaga, $name, 'child saga');
            self::assertClassName($childSubject, $name, 'child subject');
        } elseif ($childSaga !== null || $childSubject !== null || $childSharesSubject) {
            throw new SagaException(sprintf(
                "Transition '%s' is a %s but carries a Call's child saga or child subject. "
                . 'Only a Call runs another saga.',
                $name,
                $kind->value,
            ));
        }
    }

    /**
     * A Call that hands the parent's subject straight to its child.
     *
     * The wizard cannot verify this — only the author knows whether the two
     * sagas really share a subject class — so it is recorded as an assertion
     * rather than inferred, and it is what decides whether the rendered
     * `subject:` closure is an identity or a stub the author must fill in.
     */
    public function sharesSubjectWithChild(): bool
    {
        return $this->kind === SagaStepKind::Call && $this->childSharesSubject;
    }

    /**
     * @return array{kind: string, name: string, from: list<string>, to: list<string>, awaits?: string, childSaga?: string, childSubject?: string, childSharesSubject?: bool}
     */
    public function toArray(): array
    {
        $data = [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'from' => $this->from,
            'to' => $this->to,
        ];

        if ($this->awaits !== null) {
            $data['awaits'] = $this->awaits;
        }

        if ($this->childSaga !== null) {
            $data['childSaga'] = $this->childSaga;
        }

        if ($this->childSubject !== null) {
            $data['childSubject'] = $this->childSubject;
        }

        if ($this->childSharesSubject) {
            $data['childSharesSubject'] = true;
        }

        return $data;
    }

    /**
     * Rebuild a step from the decoded blueprint.
     *
     * Every field is narrowed explicitly before it is used: the array comes
     * from `json_decode`, so its values are `mixed`, and the constructor's own
     * declarations do not narrow what a caller passed to it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SagaException when the payload does not describe a step
     */
    public static function fromArray(array $data): self
    {
        $kind = self::string($data, 'kind');
        $resolved = SagaStepKind::tryFrom($kind);

        if ($resolved === null) {
            throw new SagaException(sprintf(
                "The blueprint declares an unknown step kind '%s'. Expected one of: %s.",
                $kind,
                implode(', ', array_column(SagaStepKind::cases(), 'value')),
            ));
        }

        return new self(
            $resolved,
            self::string($data, 'name'),
            self::stringList($data, 'from'),
            self::stringList($data, 'to'),
            self::optionalString($data, 'awaits'),
            self::optionalString($data, 'childSaga'),
            self::optionalString($data, 'childSubject'),
            ($data['childSharesSubject'] ?? false) === true,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new SagaException(sprintf(
            "The blueprint's step is missing the string field '%s'.",
            $key,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : throw new SagaException(sprintf(
            "The blueprint's step field '%s' must be a string.",
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
            throw new SagaException(sprintf(
                "The blueprint's step field '%s' must be a list of places.",
                $key,
            ));
        }

        $places = [];

        foreach ($value as $place) {
            if (! is_string($place)) {
                throw new SagaException(sprintf(
                    "The blueprint's step field '%s' must hold place names only.",
                    $key,
                ));
            }

            $places[] = $place;
        }

        return $places;
    }

    /**
     * @param  list<string>  $places
     * @return list<string>
     */
    private static function places(array $places, string $transition, string $which): array
    {
        if ($places === []) {
            throw new SagaException(sprintf(
                "Transition '%s' has no '%s' place. A transition moves between places, and one with "
                . 'nothing on a side can neither be drawn nor fired.',
                $transition,
                $which,
            ));
        }

        foreach ($places as $place) {
            self::assertName($place, sprintf("the '%s' place of transition '%s'", $which, $transition));
        }

        return array_values(array_unique($places));
    }

    private static function assertName(string $name, string $subject): void
    {
        if (str_starts_with($name, self::RESERVED_PREFIX)) {
            throw new SagaException(sprintf(
                "The name '%s' used by %s starts with '%s', which is reserved for the markers the runner "
                . 'journals into a saga\'s history. Pick another name.',
                $name,
                $subject,
                self::RESERVED_PREFIX,
            ));
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new SagaException(sprintf(
                "The name '%s' used by %s is not a lower-case identifier. Places and transitions must "
                . 'match %s: the graph is stored in a generated docblock, and a name that could carry a '
                . 'comment terminator or an expression is refused before it can get there.',
                $name,
                $subject,
                self::NAME_PATTERN,
            ));
        }
    }

    private static function assertClassName(?string $class, string $transition, string $which): void
    {
        if ($class === null) {
            throw new SagaException(sprintf(
                "Transition '%s' has no %s. It is a class name, and the step cannot be completed "
                . 'without it.',
                $transition,
                $which,
            ));
        }

        if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
            throw new SagaException(sprintf(
                "'%s' cannot be the %s of transition '%s': expected a plain fully-qualified class "
                . 'name matching %s.',
                $class,
                $which,
                $transition,
                self::CLASS_PATTERN,
            ));
        }
    }
}
