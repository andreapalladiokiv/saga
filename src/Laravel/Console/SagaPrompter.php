<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

/**
 * Everything the wizard has to ask someone, and the only way it can speak.
 *
 * The wizard is a script, and this is the seam that lets it be one: the dialogue
 * — which question follows which answer, what is refused and what is recorded —
 * lives in {@see SagaWizard} and knows nothing about a console, and this
 * interface is what a terminal, a test and a future `--spec` file each
 * implement differently.
 *
 * Every method is a question the wizard cannot answer itself, and each returns
 * what was said rather than validating it. Validation belongs to the rules —
 * {@see SagaBlueprint::validate()} and the constructors of {@see SagaStep} — so
 * that a prompter cannot disagree with them, and no rule has to be written
 * twice. What comes back from here is allowed to be wrong; it is not allowed to
 * be invented.
 */
interface SagaPrompter
{
    /**
     * The places the saga starts in, as the author writes them.
     *
     * Asked once, before any step. An initial place is a place by construction
     * ({@see SagaBlueprint::addInitial()}), so these are also the first places
     * the graph has.
     *
     * @return list<string>
     */
    public function initialPlaces(): array;

    /**
     * Which kind of step comes next, or null when there are no more.
     *
     * The null is the whole of the loop's exit: a saga is finished by saying so,
     * not by running out of anything.
     */
    public function stepKind(): ?SagaStepKind;

    public function stepName(SagaStepKind $kind): string;

    /**
     * The places the step leaves — more than one is a join.
     *
     * The declared places are offered because that is the point: choosing from
     * what the graph has is what makes a misspelt place impossible to introduce
     * here, and leaves the rule against undeclared arcs doing what it is
     * actually for — catching a hand-edited blueprint.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function fromPlaces(SagaStepKind $kind, array $declared): array;

    /**
     * The places the step arrives in — more than one is a fork.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function toPlaces(SagaStepKind $kind, array $declared): array;

    /** The payload type a Signal waits for. */
    public function awaitedClass(string $step): string;

    /** The saga a Call runs. */
    public function childSaga(string $step): string;

    /** The subject of the saga a Call runs — what its answer arrives as. */
    public function childSubject(string $step): string;

    /**
     * Whether the Call hands its own subject straight to the child.
     *
     * Nothing can check this — only the author knows whether the two sagas share
     * a subject class — so it is asked, and it decides whether the generated
     * mapping is an identity or a stub to fill in.
     */
    public function sharesSubjectWithChild(string $step): bool;

    /** A yes or no, for a decision the wizard must not make on its own. */
    public function confirm(string $question): bool;

    /**
     * What the rules say about the graph as it stands.
     *
     * Both levels together, because both are the author's to read: an error
     * means the step was not recorded, a warning means it was. Which is which is
     * on the issue, not on the call.
     *
     * @param  list<BlueprintIssue>  $issues
     */
    public function issues(array $issues): void;

    /** Something the author needs to know that is not a complaint. */
    public function note(string $message): void;
}
