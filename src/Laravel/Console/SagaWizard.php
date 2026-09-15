<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

use Techork\Saga\SagaException;

use function class_exists;
use function interface_exists;
use function ltrim;
use function sprintf;
use function strrpos;
use function substr;

/**
 * The conversation that turns answers into a graph.
 *
 * It is a loop around one transaction. Every answer is applied to a copy of the
 * blueprint, the copy is validated as a whole graph, and it replaces the real
 * one only if it did not break anything. That shape is what keeps this class
 * free of rules: it never asks "is this name taken" or "does that class exist",
 * it asks the model, and the model already answers both — the first as an issue
 * in {@see SagaBlueprint::validate()}, the second as an exception out of
 * {@see SagaStep}. A refusal here is a refusal from there, restated for
 * whoever is answering, and no rule is written twice.
 *
 * The loop ends one of two ways, and the difference matters. The author says
 * they are done, and the graph is what they built. Or answers keep being
 * refused — {@see MAX_REFUSALS} in a row — and the wizard stops itself, because
 * a prompter that answers wrongly (a mis-scripted test, a typo in a future
 * `--spec` file, someone leaning on the enter key) is exactly the thing least
 * able to notice that the question is not being answered. A refused answer
 * changes nothing, so nothing about the graph would ever end that loop.
 *
 * Either way the graph is returned, incomplete or not. Iterating is the point:
 * whatever got through is on disk, and the next run reads it back and carries
 * on — so a wizard that gave up halfway is a worse session, not lost work.
 */
final class SagaWizard
{
    /**
     * How many refused answers in a row end the conversation.
     *
     * Deliberately small. Someone who has missed five times running is not
     * being helped by a sixth question, and a scripted prompter that is out of
     * step with the wizard should fail its test rather than hang it.
     */
    public const MAX_REFUSALS = 5;

    private SagaBlueprint $blueprint;

    private int $refusals = 0;

    private ?string $stopped = null;

    /**
     * Classes the step being built right now will have written for it, applied
     * only if the step itself is kept.
     *
     * Held apart from the blueprint for as long as the step is unproven: a
     * promise about a payload DTO is a promise made about a step, and a step
     * that is refused takes its promise with it.
     *
     * @var list<string>
     */
    private array $pending = [];

    public function __construct(
        private readonly SagaPrompter $prompter,
        SagaBlueprint $blueprint,
    ) {
        $this->blueprint = $blueprint;
    }

    /**
     * Ask until there is nothing left to ask, and return the graph.
     *
     * The graph is built up in copies, so what comes back is not the object
     * that came in — it is the last copy that survived validation. A caller
     * iterating already has to carry the graph forward itself; reading it from
     * the return value is the only way to be sure of getting the current one.
     */
    public function run(): SagaBlueprint
    {
        // Asked once, and only when there is nowhere to start. A blueprint read
        // back out of a file already starts somewhere, and asking again would
        // offer to move where a half-built saga begins — a question nobody wants
        // on the tenth run of the same command.
        if ($this->blueprint->initial() === []) {
            $this->initial();
        }

        while ($this->stopped === null) {
            $kind = $this->prompter->stepKind();

            if ($kind === null) {
                break;
            }

            $this->record($kind);
        }

        return $this->blueprint;
    }

    /**
     * Why the wizard stopped asking before it was told to, or null when the
     * conversation ended the ordinary way.
     *
     * The caller has to be able to tell the two apart, because they are not the
     * same outcome at all: one means the graph is finished, the other means the
     * graph is a draft and the questions that would have finished it were
     * unanswerable.
     */
    public function stopped(): ?string
    {
        return $this->stopped;
    }

    /**
     * Ask for the places the saga starts in, until they can be declared.
     *
     * Declaring them is not checked against the graph, and could not be: an
     * initial place with no way out is an error, and it is the state of every
     * saga between "what does it start with?" and "what happens first?" — while
     * a place with no way in or out is a warning, and it is true of the first
     * one by definition. Both are the shape of "there are no steps yet", and
     * both would be reported about an answer that has only just been given. So
     * this is the one write that tolerates the issues of incompleteness, and
     * says nothing at all.
     */
    private function initial(): void
    {
        while ($this->stopped === null) {
            $places = $this->prompter->initialPlaces();

            if ($places === []) {
                $this->refuse([BlueprintIssue::error(
                    'A saga has to start somewhere: give at least one initial place, so there is a '
                    . 'marking for start() to set.',
                )]);

                continue;
            }

            $declared = $this->commit(
                static function (SagaBlueprint $candidate) use ($places): void {
                    foreach ($places as $place) {
                        $candidate->addInitial($place);
                    }
                },
                tolerate: true,
            );

            if ($declared) {
                return;
            }
        }
    }

    /**
     * Ask for one step, and keep it if the graph still holds up.
     *
     * A refusal returns to the top of the loop rather than to the middle of this
     * method: the questions are re-asked from the name, because nothing about a
     * step is settled until all of it is, and a half-collected edge would be a
     * worse thing to leave in a prompter's hands than a repeated question.
     */
    private function record(SagaStepKind $kind): bool
    {
        $name = $this->prompter->stepName($kind);
        $from = $this->prompter->fromPlaces($kind, $this->blueprint->places());
        $to = $this->prompter->toPlaces($kind, $this->blueprint->places());

        $this->pending = [];

        try {
            $step = $this->step($kind, $name, $from, $to);
        } catch (SagaException $e) {
            $this->refuse([BlueprintIssue::error($e->getMessage())]);

            return false;
        }

        $recorded = $this->commit(function (SagaBlueprint $candidate) use ($step): void {
            // The endpoints are declared rather than assumed. Every place this
            // wizard learns about it declares, which is what makes the rule
            // against an undeclared arc endpoint unreachable from here — and
            // leaves it doing what it is for, which is catching a graph someone
            // edited by hand.
            foreach ([...$step->from, ...$step->to] as $place) {
                $candidate->addPlace($place);
            }

            foreach ($this->pending as $class) {
                $candidate->promise($class);
            }

            $candidate->addStep($step);
        });

        if ($recorded) {
            $this->prompter->note($this->diagram());
        }

        return $recorded;
    }

    /**
     * The graph as a diagram — or, when it cannot be one yet, why not.
     *
     * A Signal whose payload this very run is about to write names a class that
     * does not exist, and a `Definition` is not built from a name nothing can
     * resolve: that is the same rule that makes a missing payload an error, and
     * the diagram is not exempt from it. The step is recorded all the same. What
     * is missing is the picture, and the exception says which class it is
     * waiting for, which is the whole of the reason.
     */
    private function diagram(): string
    {
        try {
            return $this->blueprint->toMermaid();
        } catch (SagaException $e) {
            return 'The graph cannot be drawn yet: ' . $e->getMessage();
        }
    }

    /**
     * The step the answers describe.
     *
     * Only the questions differ between the kinds; what makes a Signal a Signal
     * is its awaited type, and a Call is asked for the child it runs and the
     * subject it hands over — never for an awaited type, which it derives from
     * the child saga itself.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     *
     * @throws SagaException when the answers cannot be a step at all
     */
    private function step(SagaStepKind $kind, string $name, array $from, array $to): SagaStep
    {
        return match ($kind) {
            SagaStepKind::Transition => new SagaStep($kind, $name, $from, $to),
            SagaStepKind::Signal => new SagaStep($kind, $name, $from, $to, $this->awaited($name)),
            SagaStepKind::Call => new SagaStep(
                $kind,
                $name,
                $from,
                $to,
                null,
                $this->prompter->childSaga($name),
                $this->prompter->childSubject($name),
                $this->prompter->sharesSubjectWithChild($name),
            ),
        };
    }

    /**
     * The payload type of a Signal, and the one place the wizard offers to write
     * a class for the author.
     *
     * A payload that does not exist is an error everywhere else, and it should
     * stay one: a Signal matches with `instanceof` against that exact name, so a
     * missing class is not a warning at runtime, it is a saga that parks where
     * nothing can ever reach it. The wizard does not weaken that. It removes the
     * reason instead — the payload is the one class whose home is obvious from
     * the step that named it — and records the promise only if the offer is
     * taken. Declining leaves the rule to refuse the step, which is honest:
     * naming a class nobody is going to write is a graph that cannot work yet.
     */
    private function awaited(string $step): string
    {
        $class = $this->prompter->awaitedClass($step);

        if (self::exists($class) || $this->blueprint->isPromised($class)) {
            return $class;
        }

        if (! $this->withinNamespaceOf($class)) {
            // Refused by the rule rather than by the offer: a class in someone
            // else's namespace is a class whose owner has to write it, and a
            // generator that reached outside the namespace it was told to write
            // into would be creating files nobody asked it to own.
            $this->prompter->note(sprintf(
                '%s does not exist, and it is not in %s, which is the only namespace this command '
                . 'writes into. A payload type belongs to whoever owns its namespace. The step is '
                . 'refused until the class is there — which is the right complaint to hear now, '
                . 'rather than a saga that parks forever and never says why.',
                $class,
                $this->namespace(),
            ));

            return $class;
        }

        $agreed = $this->prompter->confirm(sprintf(
            '%s does not exist. A Signal accepts its payload with instanceof against that exact name, '
            . 'so the saga would park there and never be woken. Write an empty %s for it?',
            $class,
            self::shortName($class),
        ));

        if ($agreed) {
            $this->pending[] = $class;
        }

        return $class;
    }

    /**
     * Apply a write to a copy of the graph, and keep the copy only if the write
     * did not break anything.
     *
     * The copy is the whole of the transaction. Whether a step may exist is
     * decided by validating the finished graph and comparing it with the graph
     * before — not by a checklist written here — so a rule added to
     * {@see SagaBlueprint::validate()} starts applying to the wizard in the same
     * commit, without this method being told about it. Rules the model expresses
     * as exceptions arrive by the same door and are converted to the same kind
     * of complaint.
     *
     * Only the warnings are shown when a write succeeds. An error that was
     * already there — as opposed to one this write introduced — is not the
     * author's news, and repeating it after every step would bury the one that
     * is.
     *
     * @param  callable(SagaBlueprint): void  $write
     * @param  bool  $tolerate  whether the issues of an unfinished graph are ignored
     *                          rather than reported. True for the initial places,
     *                          and only there: see {@see initial()}.
     */
    private function commit(callable $write, bool $tolerate = false): bool
    {
        $candidate = clone $this->blueprint;

        try {
            $write($candidate);
        } catch (SagaException $e) {
            $this->refuse([BlueprintIssue::error($e->getMessage())]);

            return false;
        }

        $warnings = [];

        if (! $tolerate) {
            $added = self::added($this->blueprint->validate(), $candidate->validate());

            foreach ($added as $issue) {
                if (! $issue->isError) {
                    $warnings[] = $issue;

                    continue;
                }

                $this->refuse($added);

                return false;
            }
        }

        $this->blueprint = $candidate;
        $this->refusals = 0;

        if ($warnings !== []) {
            $this->prompter->issues($warnings);
        }

        return true;
    }

    /**
     * What the graph says now that it did not say before.
     *
     * By message and by count, because that is all an issue is: it is a value
     * rebuilt from the graph on every call, with no identity to compare, and the
     * only thing that can say "this is the same complaint as before" is what it
     * says. Counting rather than testing for presence is what keeps a second
     * identical warning from disappearing into the first.
     *
     * @param  list<BlueprintIssue>  $before
     * @param  list<BlueprintIssue>  $after
     * @return list<BlueprintIssue>
     */
    private static function added(array $before, array $after): array
    {
        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($before as $issue) {
            $seen[$issue->message] = ($seen[$issue->message] ?? 0) + 1;
        }

        $added = [];

        foreach ($after as $issue) {
            if (($seen[$issue->message] ?? 0) > 0) {
                $seen[$issue->message]--;

                continue;
            }

            $added[] = $issue;
        }

        return $added;
    }

    /**
     * Show what was wrong with an answer, and give up if that keeps happening.
     *
     * @param  list<BlueprintIssue>  $issues
     */
    private function refuse(array $issues): void
    {
        $this->prompter->issues($issues);
        $this->refusals++;

        if ($this->refusals < self::MAX_REFUSALS) {
            return;
        }

        $this->stopped = sprintf(
            '%d answers in a row could not be made into a step, so the wizard has stopped asking. '
            . 'Nothing was discarded: whatever was recorded is in the graph the command is holding, '
            . 'and running it again carries on from there.',
            self::MAX_REFUSALS,
        );

        $this->prompter->note($this->stopped);
    }

    private static function exists(string $class): bool
    {
        return class_exists($class) || interface_exists($class);
    }

    /**
     * Whether a class lives in the namespace the saga lives in.
     *
     * That namespace is the whole of what this command may write into: it was
     * given one to generate into, and generating outside it would be creating
     * files under names it did not choose.
     */
    private function withinNamespaceOf(string $class): bool
    {
        return self::namespaceOf($class) === $this->namespace();
    }

    private function namespace(): string
    {
        return self::namespaceOf($this->blueprint->saga());
    }

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
}
