<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

use Illuminate\Console\Command;

use function count;
use function explode;
use function implode;
use function is_string;
use function sprintf;
use function trim;

/**
 * The wizard, at a terminal.
 *
 * The whole class is questions and formatting, and that is the point: every
 * rule about what may be answered lives in {@see SagaWizard} and the model
 * behind it, so this one can be as thin as it is. It never checks anything — it
 * asks, hands back what was said, and lets the wizard refuse it if it is wrong.
 *
 * Two shapes of question, and the difference matters. Places are asked for as
 * free text with the declared ones printed beside the question, because a step
 * may arrive in a place nobody has named yet: a plain list to choose from would
 * make a fork impossible to express. Classes are asked for as free text too, and
 * for the same reason — the answer is a fully-qualified name the command may
 * have to write a file for.
 *
 * The answers are only ever narrowed, never interpreted: what comes back is a
 * string the wizard will parse as a name, a place or a class, and anything else
 * is treated as no answer at all.
 */
final class ConsoleSagaPrompter implements SagaPrompter
{
    public function __construct(private readonly Command $command) {}

    public function initialPlaces(): array
    {
        return self::places($this->command->ask(
            'Which place does the saga start in? It is marked there, so give it a name in the present '
            . 'tense — placed, pending, open. Comma-separate several to start in all of them.',
        ));
    }

    public function stepKind(): ?SagaStepKind
    {
        $answer = $this->command->choice(
            'What happens next?',
            [
                'transition' => 'a step the saga takes on its own',
                'signal' => 'a wait for something outside the saga',
                'call' => 'run another saga, and wait for what it comes back with',
                'done' => 'nothing — write out what there is',
            ],
            'done',
        );

        if (! is_string($answer) || $answer === 'done') {
            return null;
        }

        return SagaStepKind::tryFrom($answer);
    }

    public function stepName(SagaStepKind $kind): string
    {
        return $this->line(sprintf(
            'What is this %s called? A lower-case name, in the imperative — reserve_stock, pay, '
            . 'expire. It names the transition, and the events your listeners hang off.',
            $kind->value,
        ));
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function fromPlaces(SagaStepKind $kind, array $declared): array
    {
        return self::places($this->command->ask(sprintf(
            'Which place does it leave?%s Comma-separate several to join them.',
            self::hint($declared),
        ), self::last($declared)));
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function toPlaces(SagaStepKind $kind, array $declared): array
    {
        return self::places($this->command->ask(sprintf(
            'Which place does it arrive in?%s A name that is new declares a new place, and several '
            . 'make a fork.',
            self::hint($declared),
        )));
    }

    public function awaitedClass(string $step): string
    {
        return $this->line(sprintf(
            'Which class does "%s" wait for? The payload, by its fully-qualified name — it is what '
            . 'whoever signals the saga hands in, and what the listener reads it back as.',
            $step,
        ));
    }

    public function childSaga(string $step): string
    {
        return $this->line(sprintf(
            'Which saga does "%s" run? Its fully-qualified class name, and it has to be a saga of its '
            . 'own.',
            $step,
        ));
    }

    public function childSubject(string $step): string
    {
        return $this->line(sprintf(
            'What is that saga\'s subject? The class the parent reads "%s"\'s answer out of, which is '
            . 'the child\'s own subject — not this saga\'s.',
            $step,
        ));
    }

    public function sharesSubjectWithChild(string $step): bool
    {
        return (bool) $this->command->confirm(sprintf(
            'Does the saga "%s" runs use this same subject class? If it does, the generated step hands '
            . 'its own subject straight over, and there is nothing to write by hand.',
            $step,
        ));
    }

    public function confirm(string $question): bool
    {
        return (bool) $this->command->confirm($question);
    }

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    public function issues(array $issues): void
    {
        foreach ($issues as $issue) {
            if ($issue->isError) {
                $this->command->error($issue->message);

                continue;
            }

            $this->command->warn($issue->message);
        }
    }

    public function note(string $message): void
    {
        $this->command->line($message);
    }

    private function line(string $question): string
    {
        $answer = $this->command->ask($question);

        return is_string($answer) ? trim($answer) : '';
    }

    /**
     * @return list<string>
     */
    private static function places(mixed $answer): array
    {
        if (! is_string($answer)) {
            return [];
        }

        $places = [];

        foreach (explode(',', $answer) as $place) {
            $place = trim($place);

            if ($place !== '') {
                $places[] = $place;
            }
        }

        return $places;
    }

    /**
     * What the graph already has, printed beside the question.
     *
     * A misspelt place is a new place and a starting place at that, if it is the
     * first one touched — so the declared names are shown where they can be
     * copied from rather than remembered.
     *
     * @param  list<string>  $declared
     */
    private static function hint(array $declared): string
    {
        if ($declared === []) {
            return ' No place is declared yet, so whatever you write becomes the first one.';
        }

        return ' Declared: ' . implode(', ', $declared) . '.';
    }

    /**
     * The place a step most likely continues from.
     *
     * The last one declared, which is what the graph grew by — an empty answer
     * then means "the step after the last one", and that is the ordinary case
     * while a saga is being built.
     *
     * @param  list<string>  $declared
     */
    private static function last(array $declared): ?string
    {
        $count = count($declared);

        return $count === 0 ? null : $declared[$count - 1];
    }
}
