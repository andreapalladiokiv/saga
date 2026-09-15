<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console\Fixtures;

use LogicException;
use Techork\Saga\Laravel\Console\BlueprintIssue;
use Techork\Saga\Laravel\Console\SagaPrompter;
use Techork\Saga\Laravel\Console\SagaStepKind;

use function array_map;
use function array_shift;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * A prompter that answers from a script, and checks the questions.
 *
 * Two tests are worth having here and only one is obvious. The visible one is
 * that the wizard builds the graph the script describes. The other is that it
 * asked for it: the script is a list of `[method, answer]` pairs, and an answer
 * is only handed over when the wizard calls the method the script paired it
 * with. Without that check a wizard that asked the wrong question — because a
 * branch was reordered, or a kind was mistaken for another — would quietly
 * consume an answer written for something else and the test would pass on a
 * graph nobody described.
 *
 * Notes and issues are recorded rather than scripted: they are what the wizard
 * says, not what it asks, and pinning their exact wording in the script would
 * make every message edit a test edit.
 */
final class ScriptedSagaPrompter implements SagaPrompter
{
    /** @var list<array{string, mixed}> */
    private array $script;

    /** @var list<string> */
    private array $said = [];

    /** @var list<string> */
    private array $confirmed = [];

    /** @var array<string, list<list<string>>> */
    private array $offered = [];

    /**
     * @param  list<array{string, mixed}>  $script  `[method, answer]` pairs, in the
     *                                              order the wizard is expected to ask
     */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    /**
     * @return list<string>
     */
    public function initialPlaces(): array
    {
        return $this->strings('initialPlaces');
    }

    public function stepKind(): ?SagaStepKind
    {
        $answer = $this->take('stepKind');

        if ($answer === null || $answer instanceof SagaStepKind) {
            return $answer;
        }

        throw $this->wrong('stepKind', 'a SagaStepKind or null', $answer);
    }

    public function stepName(SagaStepKind $kind): string
    {
        return $this->string('stepName');
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function fromPlaces(SagaStepKind $kind, array $declared): array
    {
        $this->offered['fromPlaces'][] = $declared;

        return $this->strings('fromPlaces');
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function toPlaces(SagaStepKind $kind, array $declared): array
    {
        $this->offered['toPlaces'][] = $declared;

        return $this->strings('toPlaces');
    }

    public function awaitedClass(string $step): string
    {
        return $this->string('awaitedClass');
    }

    public function childSaga(string $step): string
    {
        return $this->string('childSaga');
    }

    public function childSubject(string $step): string
    {
        return $this->string('childSubject');
    }

    public function sharesSubjectWithChild(string $step): bool
    {
        $answer = $this->take('sharesSubjectWithChild');

        return is_bool($answer) ? $answer : throw $this->wrong('sharesSubjectWithChild', 'a bool', $answer);
    }

    public function confirm(string $question): bool
    {
        $this->confirmed[] = $question;

        $answer = $this->take('confirm');

        return is_bool($answer) ? $answer : throw $this->wrong('confirm', 'a bool', $answer);
    }

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    public function issues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->said[] = ($issue->isError ? 'error: ' : 'warning: ') . $issue->message;
        }
    }

    public function note(string $message): void
    {
        $this->said[] = $message;
    }

    /**
     * Everything the wizard said — issues and notes alike.
     *
     * @return list<string>
     */
    public function said(): array
    {
        return $this->said;
    }

    public function hasSaid(string $needle): bool
    {
        foreach ($this->said as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The questions the wizard put to {@see confirm()}, which are the only place
     * it asks for a decision rather than for data.
     *
     * @return list<string>
     */
    public function confirmed(): array
    {
        return $this->confirmed;
    }

    /**
     * What the graph offered when the wizard asked where a step goes, in the
     * order it was asked.
     *
     * Worth pinning: offering the declared places is what makes a misspelt place
     * impossible to introduce from here, and a wizard that asked without them
     * would still pass every test that only reads the answers.
     *
     * @return list<list<string>>
     */
    public function offered(string $method): array
    {
        return $this->offered[$method] ?? [];
    }

    /**
     * The methods still waiting in the script.
     *
     * @return list<string>
     */
    public function remaining(): array
    {
        return array_map(static fn (array $pair): string => $pair[0], $this->script);
    }

    /**
     * Assert the wizard asked everything the script was written for.
     *
     * A wizard that stopped early leaves answers unread, which is a bug in the
     * wizard or in the script, and either way it is not something to notice by
     * counting questions in another test.
     */
    public function assertFinished(): void
    {
        if ($this->script !== []) {
            throw new LogicException(sprintf(
                'The wizard stopped asking with %d answer(s) left in the script: %s.',
                count($this->script),
                implode(', ', $this->remaining()),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function strings(string $method): array
    {
        $answer = $this->take($method);

        if (! is_array($answer)) {
            throw $this->wrong($method, 'a list of strings', $answer);
        }

        $strings = [];

        foreach ($answer as $item) {
            if (! is_string($item)) {
                throw $this->wrong($method, 'a list of strings', $answer);
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private function string(string $method): string
    {
        $answer = $this->take($method);

        return is_string($answer) ? $answer : throw $this->wrong($method, 'a string', $answer);
    }

    /**
     * Hand over the next answer — if it is the one this question was written for.
     */
    private function take(string $method): mixed
    {
        $next = array_shift($this->script);

        if ($next === null) {
            throw new LogicException(sprintf(
                'The wizard asked %s() with nothing left in the script. It asked more questions than '
                . 'the test scripted, which usually means a step was refused that was meant to be kept.',
                $method,
            ));
        }

        [$expected, $answer] = $next;

        if ($expected !== $method) {
            throw new LogicException(sprintf(
                'The wizard called %s() where the script has an answer for %s(). A wizard that asks '
                . 'the wrong question would otherwise consume an answer meant for another one, and '
                . 'the test would pass on a graph nobody described.',
                $method,
                $expected,
            ));
        }

        return $answer;
    }

    private function wrong(string $method, string $expected, mixed $answer): LogicException
    {
        return new LogicException(sprintf(
            'The scripted answer to %s() is not %s: it is %s.',
            $method,
            $expected,
            get_debug_type($answer),
        ));
    }
}
