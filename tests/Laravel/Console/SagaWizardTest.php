<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console;

use PHPUnit\Framework\TestCase;
use Techork\Saga\Laravel\Console\BlueprintIssue;
use Techork\Saga\Laravel\Console\SagaBlueprint;
use Techork\Saga\Laravel\Console\SagaStep;
use Techork\Saga\Laravel\Console\SagaStepKind;
use Techork\Saga\Laravel\Console\SagaWizard;
use Techork\Saga\Tests\Checkout\PaymentReceived;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ChildSubject;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ParameterlessChildSaga;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ScriptedSagaPrompter;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * The wizard is one loop around one transaction, and this is where both of
 * those are pinned.
 *
 * The dialogue is checked through {@see ScriptedSagaPrompter}, which refuses to
 * hand over an answer the script paired with a different question — so a test
 * here fails if the wizard asks the wrong thing, not only if it builds the wrong
 * graph. And every graph the scripts describe is validated at the end, because
 * a wizard that accepted a step the runtime would choke on has failed at the
 * only job it has that the scaffolder does not already do.
 */
final class SagaWizardTest extends TestCase
{
    private const SAGA = 'App\Sagas\OrderSaga';

    private const SUBJECT = 'App\Sagas\OrderSubject';

    private const AWAITED = PaymentReceived::class;

    private const CHILD = ParameterlessChildSaga::class;

    private const CHILD_SUBJECT = ChildSubject::class;

    /**
     * A payload nobody has written, in the namespace the command writes into.
     */
    private const UNWRITTEN = 'App\Sagas\PaymentCaptured';

    private static function blueprint(): SagaBlueprint
    {
        return new SagaBlueprint(self::SAGA, self::SUBJECT);
    }

    private function wizard(ScriptedSagaPrompter $prompter, ?SagaBlueprint $blueprint = null): SagaWizard
    {
        return new SagaWizard($prompter, $blueprint ?? self::blueprint());
    }

    /**
     * The questions one step costs, in the order the wizard asks them.
     *
     * The arcs come before whatever the kind asks for itself, which is why the
     * last parameter exists: a Signal's awaited type and a Call's child are
     * questions about a step that already has a shape.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @param  list<array{string, mixed}>  $then
     * @return list<array{string, mixed}>
     */
    private static function step(
        SagaStepKind $kind,
        string $name,
        array $from,
        array $to,
        array $then = [],
    ): array {
        return [
            ['stepKind', $kind],
            ['stepName', $name],
            ['fromPlaces', $from],
            ['toPlaces', $to],
            ...$then,
        ];
    }

    /**
     * Everything the prompter said at one level of severity — or all of it, for
     * an empty level, because a note is not a complaint and is asked for by
     * content rather than by severity.
     *
     * @return list<string>
     */
    private static function said(ScriptedSagaPrompter $prompter, string $level): array
    {
        if ($level === '') {
            return $prompter->said();
        }

        $prefix = $level . ': ';

        return array_values(array_filter(
            $prompter->said(),
            static fn (string $message): bool => str_starts_with($message, $prefix),
        ));
    }

    private static function assertSaid(ScriptedSagaPrompter $prompter, string $level, string $needle): void
    {
        $found = array_filter(
            self::said($prompter, $level),
            static fn (string $message): bool => str_contains($message, $needle),
        );

        self::assertNotEmpty($found, sprintf(
            'The wizard said no %s mentioning "%s". It said: %s',
            $level,
            $needle,
            implode(' | ', $prompter->said()) ?: '(nothing)',
        ));
    }

    /**
     * @return list<string>
     */
    private static function errors(SagaBlueprint $blueprint): array
    {
        return array_values(array_map(
            static fn (BlueprintIssue $issue): string => $issue->message,
            array_filter(
                $blueprint->validate(),
                static fn (BlueprintIssue $issue): bool => $issue->isError,
            ),
        ));
    }

    // ------------------------------------------------------------------ three steps

    public function test_a_script_of_three_steps_becomes_the_graph_it_describes(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ...self::step(SagaStepKind::Signal, 'pay', ['reserved'], ['paid'], [
                ['awaitedClass', self::AWAITED],
            ]),
            ...self::step(SagaStepKind::Call, 'intent', ['paid'], ['settled'], [
                ['childSaga', self::CHILD],
                ['childSubject', self::CHILD_SUBJECT],
                ['sharesSubjectWithChild', true],
            ]),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        self::assertNull($wizard->stopped());
        self::assertSame(['placed'], $blueprint->initial());
        self::assertSame(['placed', 'reserved', 'paid', 'settled'], $blueprint->places());

        $steps = $blueprint->steps();
        self::assertCount(3, $steps);

        self::assertSame('reserve_stock', $steps[0]->name);
        self::assertSame(SagaStepKind::Transition, $steps[0]->kind);
        self::assertSame(['placed'], $steps[0]->from);
        self::assertSame(['reserved'], $steps[0]->to);
        self::assertNull($steps[0]->awaits);

        self::assertSame('pay', $steps[1]->name);
        self::assertSame(SagaStepKind::Signal, $steps[1]->kind);
        self::assertSame(self::AWAITED, $steps[1]->awaits);

        self::assertSame('intent', $steps[2]->name);
        self::assertSame(SagaStepKind::Call, $steps[2]->kind);
        self::assertSame(self::CHILD, $steps[2]->childSaga);
        self::assertSame(self::CHILD_SUBJECT, $steps[2]->childSubject);
        self::assertTrue($steps[2]->sharesSubjectWithChild());

        // Nothing the wizard built is left for the runtime to object to. This is
        // the assertion the whole class exists for: the same validate() that
        // refused the bad answers above has to pass the ones that were kept.
        self::assertSame([], self::errors($blueprint));

        $prompter->assertFinished();
    }

    public function test_the_graph_is_drawn_after_every_step(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ...self::step(SagaStepKind::Transition, 'charge', ['reserved'], ['charged']),
            ['stepKind', null],
        ]);

        $this->wizard($prompter)->run();

        $diagrams = array_filter($prompter->said(), static fn (string $m): bool => str_contains($m, 'graph LR'));

        // One per step, not one at the end: the diagram is the feedback that
        // says what the answer just did to the graph.
        self::assertCount(2, $diagrams);
        self::assertSaid($prompter, '', 'charge');
    }

    public function test_every_step_is_offered_the_places_the_graph_already_has(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ...self::step(SagaStepKind::Transition, 'charge', ['reserved'], ['charged']),
            ['stepKind', null],
        ]);

        $this->wizard($prompter)->run();

        // Choosing from the declared places is what makes a misspelt place
        // impossible to introduce here, so what the wizard offers is part of the
        // contract, not a convenience for the console to render.
        self::assertSame(
            [['placed'], ['placed', 'reserved']],
            $prompter->offered('fromPlaces'),
        );
    }

    // ------------------------------------------------------------------ refusals

    public function test_an_answer_the_rules_refuse_is_asked_again(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            // A space is not a lower-case identifier, and the name would land in
            // a docblock: refused by SagaStep, before anything is recorded.
            ...self::step(SagaStepKind::Transition, 'Reserve Stock', ['placed'], ['reserved']),
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        self::assertNull($wizard->stopped());
        self::assertCount(1, $blueprint->steps());
        self::assertSame('reserve_stock', $blueprint->steps()[0]->name);
        self::assertSame(['placed', 'reserved'], $blueprint->places());
        self::assertSaid($prompter, 'error', 'Reserve Stock');
        $prompter->assertFinished();
    }

    public function test_warnings_are_shown_without_refusing_the_step(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Signal, 'pay', ['placed'], ['reserved'], [
                ['awaitedClass', self::AWAITED],
            ]),
            ...self::step(SagaStepKind::Transition, 'expire', ['placed'], ['expired']),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        // A place left by both a Signal and an ordinary transition is legal and
        // suspicious: whether the guard holds the deadline is the part nothing
        // can check, so it is said and not acted on.
        self::assertSame(2, count($blueprint->steps()));
        self::assertSaid($prompter, 'warning', 'left by both a Signal');
        self::assertSame([], self::said($prompter, 'error'));
        self::assertNull($wizard->stopped());
        $prompter->assertFinished();
    }

    public function test_stops_asking_when_the_answers_keep_being_refused(): void
    {
        $script = [['initialPlaces', ['placed']]];

        for ($attempt = 0; $attempt < SagaWizard::MAX_REFUSALS; $attempt++) {
            $script = [
                ...$script,
                ...self::step(SagaStepKind::Transition, sprintf('Reserve Stock %d', $attempt), ['placed'], ['reserved']),
            ];
        }

        $prompter = new ScriptedSagaPrompter($script);
        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        $stopped = $wizard->stopped();

        self::assertNotNull($stopped, 'A mis-scripted dialogue has to end by itself.');
        self::assertStringContainsString((string) SagaWizard::MAX_REFUSALS, $stopped);

        // Nothing was lost on the way out: the start place is there, and the
        // failures left no trace — a refused answer changes nothing.
        self::assertSame([], $blueprint->steps());
        self::assertSame(['placed'], $blueprint->initial());

        self::assertCount(SagaWizard::MAX_REFUSALS, self::said($prompter, 'error'));
        self::assertSaid($prompter, '', 'stopped asking');

        // And it stopped rather than asking a sixth time: the script has exactly
        // MAX_REFUSALS attempts in it, so another question would have nothing to
        // take and the prompter would throw.
        $prompter->assertFinished();
    }

    // ------------------------------------------------------------------ unfinished graphs

    public function test_a_graph_with_nothing_but_its_start_is_left_for_the_command_to_judge(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        self::assertNull($wizard->stopped());
        self::assertSame([], $blueprint->steps());
        self::assertSame(['placed'], $blueprint->initial());

        // An initial place with no way out is an error, and it is the state of
        // every saga between "what does it start with" and "what happens first".
        // Reporting it here — or refusing the answer that makes it go away —
        // would be telling the author about a draft, in the middle of drafting.
        self::assertNotSame([], self::errors($blueprint));
        self::assertSame([], $prompter->said());

        $prompter->assertFinished();
    }

    // ------------------------------------------------------------------ carrying on

    public function test_carries_on_from_a_blueprint_read_back_out_of_a_file(): void
    {
        $existing = SagaBlueprint::fromSource($this->ordered()->toDocblock());

        self::assertNotNull($existing);

        $prompter = new ScriptedSagaPrompter([
            // No initialPlaces: the graph already starts somewhere, and the
            // wizard does not offer to move it.
            ...self::step(SagaStepKind::Signal, 'ship', ['charged'], ['shipped'], [
                ['awaitedClass', self::AWAITED],
            ]),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter, $existing);
        $blueprint = $wizard->run();

        self::assertNull($wizard->stopped());
        self::assertSame(['placed'], $blueprint->initial());
        self::assertSame(['placed', 'reserved', 'charged', 'shipped'], $blueprint->places());
        self::assertSame(
            ['reserve_stock', 'charge', 'ship'],
            array_map(static fn (SagaStep $step): string => $step->name, $blueprint->steps()),
        );
        self::assertSame([], self::errors($blueprint));
        $prompter->assertFinished();
    }

    /**
     * A saga with a start, a terminal place, and nothing to complain about.
     */
    private function ordered(): SagaBlueprint
    {
        $blueprint = self::blueprint();

        $blueprint->addInitial('placed');
        $blueprint->addPlace('reserved');
        $blueprint->addPlace('charged');

        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['reserved'], ['charged']));

        return $blueprint;
    }

    // ------------------------------------------------------------------ payloads

    public function test_offers_to_write_the_payload_a_signal_awaits(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Signal, 'capture', ['placed'], ['captured'], [
                ['awaitedClass', self::UNWRITTEN],
                ['confirm', true],
            ]),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        self::assertNull($wizard->stopped());
        self::assertCount(1, $blueprint->steps());

        // The promise is the whole reason the step was accepted: the rule that
        // refuses a Signal awaiting a class that does not exist is right, and it
        // is answered by saying out loud that the class is coming.
        self::assertTrue($blueprint->isPromised(self::UNWRITTEN));
        self::assertSame([], self::said($prompter, 'error'));

        $questions = $prompter->confirmed();
        self::assertCount(1, $questions);
        self::assertStringContainsString(self::UNWRITTEN, $questions[0]);
        self::assertStringContainsString('instanceof', $questions[0]);

        // The diagram waits for the class: a Definition is not built from a name
        // nothing resolves. The step is recorded; only the picture is missing.
        self::assertSaid($prompter, '', 'cannot be drawn yet');

        $prompter->assertFinished();
    }

    public function test_a_payload_nobody_will_write_leaves_the_rule_to_refuse_the_step(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Signal, 'capture', ['placed'], ['captured'], [
                ['awaitedClass', self::UNWRITTEN],
                ['confirm', false],
            ]),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        self::assertNull($wizard->stopped());
        self::assertSame([], $blueprint->steps());
        self::assertFalse($blueprint->isPromised(self::UNWRITTEN));
        self::assertSaid($prompter, 'error', self::UNWRITTEN);
        $prompter->assertFinished();
    }

    public function test_a_payload_outside_the_sagas_namespace_is_never_offered(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Signal, 'capture', ['placed'], ['captured'], [
                ['awaitedClass', 'Acme\Billing\PaymentCaptured'],
            ]),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        // Not asked, because the answer would not matter: this command writes
        // into one namespace, and that class is not in it.
        self::assertSame([], $prompter->confirmed());
        self::assertSame([], $blueprint->steps());
        self::assertSaid($prompter, '', 'Acme\Billing');
        self::assertSaid($prompter, '', 'not in App\Sagas');
        self::assertSaid($prompter, 'error', 'Acme\Billing\PaymentCaptured');
        $prompter->assertFinished();
    }

    public function test_a_payload_agreed_to_earlier_is_not_questioned_again(): void
    {
        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Signal, 'capture', ['placed'], ['captured'], [
                ['awaitedClass', self::UNWRITTEN],
                ['confirm', true],
            ]),
            ...self::step(SagaStepKind::Signal, 'retry_capture', ['captured'], ['retried'], [
                ['awaitedClass', self::UNWRITTEN],
            ]),
            ['stepKind', null],
        ]);

        $wizard = $this->wizard($prompter);
        $blueprint = $wizard->run();

        self::assertCount(2, $blueprint->steps());
        self::assertCount(1, $prompter->confirmed(), 'One payload, one question.');
        self::assertSame([], self::said($prompter, 'error'));
        $prompter->assertFinished();
    }
}
