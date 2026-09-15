<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console;

use JsonException;
use PHPUnit\Framework\TestCase;
use Techork\Saga\Laravel\Console\BlueprintIssue;
use Techork\Saga\Laravel\Console\SagaBlueprint;
use Techork\Saga\Laravel\Console\SagaStep;
use Techork\Saga\Laravel\Console\SagaStepKind;
use Techork\Saga\SagaException;
use Techork\Saga\Signal;
use Techork\Saga\Tests\Call\PaymentIntentSubject;
use Techork\Saga\Tests\Checkout\PaymentReceived;
use Techork\Saga\Tests\Fixtures\AlphaSaga;

use function array_filter;
use function array_keys;
use function array_map;
use function explode;
use function implode;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * The blueprint is the graph the whole feature is built on: the wizard edits
 * it, the scaffolder renders from it, and a second run reads it back out of a
 * generated file to carry on. So every rule it enforces is a rule that stops a
 * saga from silently parking somewhere nothing can reach — and each one is
 * pinned here with the failure it prevents, not just the message it produces.
 */
final class SagaBlueprintTest extends TestCase
{
    private const CHILD_SAGA = AlphaSaga::class;

    private const AWAITED = PaymentReceived::class;

    /**
     * A saga with a start, a terminal place, and nothing to complain about.
     */
    private function ordered(): SagaBlueprint
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');

        $blueprint->addInitial('placed');
        $blueprint->addPlace('reserved');
        $blueprint->addPlace('charged');

        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['reserved'], ['charged']));

        return $blueprint;
    }

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    private static function text(array $issues): string
    {
        return implode("\n", array_map(
            static fn (BlueprintIssue $issue): string => $issue->message,
            $issues,
        ));
    }

    /**
     * @param  list<BlueprintIssue>  $issues
     */
    private static function assertHasError(array $issues, string $needle): void
    {
        $found = array_filter(
            $issues,
            static fn (BlueprintIssue $issue): bool => $issue->isError && str_contains($issue->message, $needle),
        );

        self::assertNotEmpty($found, sprintf(
            'No error mentioning "%s". The reported issues were: %s',
            $needle,
            self::text($issues) ?: '(none)',
        ));
    }

    // ---------------------------------------------------------------- a clean graph

    public function test_a_well_formed_saga_reports_nothing(): void
    {
        self::assertSame([], $this->ordered()->validate());
    }

    public function test_a_signal_whose_awaited_class_exists_is_accepted(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['charged'], ['paid'], self::AWAITED));

        self::assertSame([], $blueprint->validate());
    }

    public function test_a_call_to_a_real_saga_with_a_real_child_subject_is_accepted(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['charged'],
            ['paid'],
            childSaga: self::CHILD_SAGA,
            childSubject: PaymentIntentSubject::class,
        ));

        self::assertSame([], $blueprint->validate());
    }

    // ---------------------------------------------------------------- errors

    public function test_an_empty_blueprint_reports_both_of_its_missing_halves(): void
    {
        $issues = (new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject'))->validate();

        self::assertCount(2, $issues);
        self::assertHasError($issues, 'has no places');
        self::assertHasError($issues, 'has no initial place');
    }

    public function test_a_duplicate_transition_name_is_an_error(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['placed'], ['charged']));

        self::assertHasError($blueprint->validate(), 'declared more than once');
    }

    public function test_an_arc_that_touches_an_undeclared_place_is_an_error(): void
    {
        // The typo this catches is the silent one: Symfony's Definition would
        // invent "reservd" from the arc, and if it were touched first it would
        // become the saga's initial marking without a word.
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reservd']));

        self::assertHasError($blueprint->validate(), 'reservd');
        self::assertSame(['placed'], $blueprint->places());
    }

    public function test_an_initial_place_with_no_outgoing_transition_is_an_error(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addPlace('reserved');

        self::assertHasError($blueprint->validate(), 'cannot start');
    }

    public function test_a_signal_awaiting_a_class_that_does_not_exist_is_an_error(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged'], 'App\Missing\PaymentReceived'));

        self::assertHasError($blueprint->validate(), 'App\Missing\PaymentReceived');
    }

    public function test_a_call_running_a_class_that_is_not_a_saga_is_an_error(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['reserved'],
            ['charged'],
            childSaga: self::AWAITED,
            childSubject: PaymentIntentSubject::class,
        ));

        self::assertHasError($blueprint->validate(), 'is not a Techork\Saga\Saga');
    }

    public function test_a_call_whose_child_does_not_exist_yet_is_only_a_warning(): void
    {
        // Building the caller before the child is the order people actually
        // work in. The step stays representable — it is rendered as a helper
        // that throws — so refusing it would mean no saga could be written
        // first.
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['charged'],
            ['paid'],
            childSaga: 'App\Sagas\MissingSaga',
            childSubject: PaymentIntentSubject::class,
        ));

        $issues = $blueprint->validate();

        self::assertCount(1, $issues);
        self::assertFalse($issues[0]->isError);
        self::assertStringContainsString('App\Sagas\MissingSaga', $issues[0]->message);
        self::assertStringContainsString('php artisan make:saga MissingSaga', $issues[0]->message);
    }

    public function test_a_call_whose_child_subject_does_not_exist_yet_is_only_a_warning(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['charged'],
            ['paid'],
            childSaga: self::CHILD_SAGA,
            childSubject: 'App\Sagas\MissingSubject',
        ));

        $issues = $blueprint->validate();

        self::assertCount(1, $issues);
        self::assertFalse($issues[0]->isError);
        self::assertStringContainsString('App\Sagas\MissingSubject', $issues[0]->message);
    }

    // ---------------------------------------------------------------- warnings

    public function test_a_place_left_by_a_signal_and_an_ordinary_transition_is_a_warning(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addPlace('paid');
        $blueprint->addPlace('expired');

        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['placed'], ['paid'], self::AWAITED));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'expire', ['placed'], ['expired']));

        $issues = $blueprint->validate();

        self::assertCount(1, $issues);
        self::assertFalse($issues[0]->isError);
        self::assertStringContainsString('both a Signal and an ordinary transition', $issues[0]->message);
    }

    public function test_a_place_nothing_touches_is_a_warning(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('forgotten');

        $issues = $blueprint->validate();

        self::assertCount(1, $issues);
        self::assertFalse($issues[0]->isError);
        self::assertStringContainsString('forgotten', $issues[0]->message);
    }

    public function test_a_graph_with_no_terminal_place_is_a_warning(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('a');
        $blueprint->addPlace('b');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'go', ['a'], ['b']));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'back', ['b'], ['a']));

        $issues = $blueprint->validate();

        self::assertCount(1, $issues);
        self::assertFalse($issues[0]->isError);
        self::assertStringContainsString('never ends', $issues[0]->message);
    }

    public function test_a_warning_does_not_hide_an_error(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('forgotten');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'charge', ['placed'], ['charged']));

        $levels = array_map(static fn (BlueprintIssue $issue): bool => $issue->isError, $blueprint->validate());

        self::assertContains(true, $levels);
        self::assertContains(false, $levels);
    }

    // ---------------------------------------------------------------- what a step refuses outright

    public function test_a_signal_must_declare_what_it_awaits(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('has no awaited type');

        new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged']);
    }

    public function test_only_a_signal_may_declare_an_awaited_type(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('Only a Signal awaits a payload');

        new SagaStep(SagaStepKind::Transition, 'pay', ['reserved'], ['charged'], self::AWAITED);
    }

    public function test_only_a_call_may_name_a_child_saga(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('Only a Call runs another saga');

        new SagaStep(SagaStepKind::Transition, 'pay', ['reserved'], ['charged'], null, self::CHILD_SAGA);
    }

    public function test_a_call_must_declare_the_subject_it_builds_for_its_child(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('has no child subject');

        new SagaStep(SagaStepKind::Call, 'pay', ['reserved'], ['charged'], null, self::CHILD_SAGA);
    }

    public function test_a_transition_must_move_between_places(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage("has no 'from' place");

        new SagaStep(SagaStepKind::Transition, 'reserve_stock', [], ['reserved']);
    }

    public function test_a_transition_keeps_each_place_once(): void
    {
        $step = new SagaStep(SagaStepKind::Transition, 'fork', ['placed', 'placed'], ['a', 'b']);

        self::assertSame(['placed'], $step->from);
    }

    public function test_a_name_cannot_carry_a_comment_terminator(): void
    {
        // A place name is written into a docblock. "*/" would end the comment
        // the blueprint lives in and turn the rest of the file into PHP.
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('not a lower-case identifier');

        new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved*/']);
    }

    public function test_the_runners_own_marker_prefix_is_refused(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('reserved for the markers');

        new SagaStep(SagaStepKind::Transition, '!saga:rollback_failed', ['placed'], ['reserved']);
    }

    public function test_a_class_name_must_be_a_plain_fqcn(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('expected a plain fully-qualified class name');

        new SagaBlueprint('App\Sagas\OrderSaga;', 'App\Sagas\OrderSubject');
    }

    public function test_a_place_name_must_be_a_plain_identifier(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('not usable as a place name');

        $blueprint->addPlace('placed or true');
    }

    // ---------------------------------------------------------------- places

    public function test_a_place_is_declared_once_however_often_it_is_named(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addPlace('placed');
        $blueprint->addInitial('placed');

        self::assertSame(['placed', 'reserved', 'charged'], $blueprint->places());
        self::assertSame(['placed'], $blueprint->initial());
    }

    public function test_adding_a_step_declares_no_place(): void
    {
        // The invariant the typo check rests on: if addStep declared the places
        // its arcs name, every mistake would become a place and validate()
        // would have nothing left to report.
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']));

        self::assertSame([], $blueprint->places());
    }

    public function test_a_place_an_ordinary_transition_leaves_is_not_a_parking_place(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged'], self::AWAITED));

        self::assertFalse($blueprint->isParkingPlace('reserved'));
    }

    public function test_a_place_only_a_signal_leaves_is_a_parking_place(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('awaiting_payment');
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['awaiting_payment'], ['paid'], self::AWAITED));

        self::assertTrue($blueprint->isParkingPlace('awaiting_payment'));
    }

    public function test_a_place_only_a_call_leaves_is_a_parking_place_too(): void
    {
        // A Call is a Signal as far as the runner is concerned: it queues
        // nothing and waits for the child to report back.
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('paying');
        $blueprint->addPlace('paid');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['paying'],
            ['paid'],
            childSaga: self::CHILD_SAGA,
            childSubject: PaymentIntentSubject::class,
        ));

        self::assertTrue($blueprint->isParkingPlace('paying'));
    }

    public function test_a_place_with_no_way_out_is_not_a_parking_place(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('new');

        self::assertFalse($blueprint->isParkingPlace('new'));
    }

    // ---------------------------------------------------------------- the definition

    public function test_the_initial_marking_is_stated_rather_than_left_to_symfony(): void
    {
        // Definition promotes the first place it is given when no marking is
        // passed, so the saga would start wherever the list happens to begin.
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addPlace('audit');
        $blueprint->addInitial('placed');
        $blueprint->addPlace('reserved');
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']));

        $definition = $blueprint->toDefinition();

        self::assertSame(['audit', 'placed', 'reserved'], array_keys($definition->getPlaces()));
        self::assertSame(['placed'], $definition->getInitialPlaces());
    }

    public function test_a_single_arc_renders_as_a_single_place_on_each_side(): void
    {
        $transition = $this->ordered()->toDefinition()->getTransitions()[0];

        self::assertSame('reserve_stock', $transition->getName());
        self::assertSame(['placed'], $transition->getFroms());
        self::assertSame(['reserved'], $transition->getTos());
    }

    public function test_a_fork_and_a_join_keep_every_place_they_name(): void
    {
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addPlace('approved');
        $blueprint->addPlace('rejected');
        $blueprint->addPlace('settled');

        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'decide', ['placed'], ['approved', 'rejected']));
        $blueprint->addStep(new SagaStep(SagaStepKind::Transition, 'close', ['approved', 'rejected'], ['settled']));

        $transitions = $blueprint->toDefinition()->getTransitions();

        self::assertSame(['approved', 'rejected'], $transitions[0]->getTos());
        self::assertSame(['approved', 'rejected'], $transitions[1]->getFroms());
        self::assertSame(['settled'], $transitions[1]->getTos());
    }

    public function test_a_signal_is_drawn_as_a_wait_on_the_type_it_awaits(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged'], self::AWAITED));

        $signal = $blueprint->toDefinition()->getTransitions()[2];

        self::assertInstanceOf(Signal::class, $signal);
        self::assertSame(self::AWAITED, $signal->awaits);
    }

    public function test_a_call_is_drawn_as_a_wait_on_the_child_saga(): void
    {
        // A blueprint cannot instantiate a child saga, so the arc is drawn as
        // the Signal it will become. It is only ever drawn — never run.
        $blueprint = new SagaBlueprint('App\Sagas\OrderSaga', 'App\Sagas\OrderSubject');
        $blueprint->addInitial('placed');
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'pay',
            ['placed'],
            ['paid'],
            childSaga: self::CHILD_SAGA,
            childSubject: PaymentIntentSubject::class,
        ));

        $call = $blueprint->toDefinition()->getTransitions()[0];

        self::assertInstanceOf(Signal::class, $call);
        self::assertSame(self::CHILD_SAGA, $call->awaits);
    }

    public function test_a_graph_that_references_a_class_that_does_not_exist_cannot_be_drawn(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged'], 'App\Missing\PaymentReceived'));

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('App\Missing\PaymentReceived');

        $blueprint->toDefinition();
    }

    // ---------------------------------------------------------------- mermaid

    public function test_the_diagram_carries_every_place_and_every_transition(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged'], self::AWAITED));

        $mermaid = $blueprint->toMermaid();

        self::assertStringStartsWith('graph LR', $mermaid);

        foreach (['placed', 'reserved', 'charged', 'reserve_stock', 'charge', 'pay'] as $name) {
            self::assertStringContainsString($name, $mermaid);
        }
    }

    // ---------------------------------------------------------------- json

    public function test_a_blueprint_survives_a_round_trip_through_json(): void
    {
        $blueprint = $this->ordered();
        $blueprint->addStep(new SagaStep(SagaStepKind::Signal, 'pay', ['reserved'], ['charged'], self::AWAITED));
        $blueprint->addStep(new SagaStep(
            SagaStepKind::Call,
            'intent',
            ['charged'],
            ['settled'],
            childSaga: self::CHILD_SAGA,
            childSubject: PaymentIntentSubject::class,
            childSharesSubject: true,
        ));

        $restored = SagaBlueprint::fromJson($blueprint->toJson());

        self::assertSame($blueprint->toJson(), $restored->toJson());
        self::assertSame($blueprint->saga(), $restored->saga());
        self::assertSame($blueprint->subject(), $restored->subject());
        self::assertSame($blueprint->places(), $restored->places());
        self::assertSame($blueprint->initial(), $restored->initial());
        self::assertCount(4, $restored->steps());
        self::assertTrue($restored->steps()[3]->sharesSubjectWithChild());
    }

    public function test_the_renderer_stamp_survives_a_round_trip(): void
    {
        $blueprint = $this->ordered();
        self::assertNull($blueprint->renderer());
        self::assertNull($blueprint->bodyHash());

        $blueprint->stamp(SagaBlueprint::RENDERER_VERSION, 'sha256:abc');

        $restored = SagaBlueprint::fromJson($blueprint->toJson());

        self::assertSame(SagaBlueprint::RENDERER_VERSION, $restored->renderer());
        self::assertSame('sha256:abc', $restored->bodyHash());
    }

    public function test_a_blueprint_written_by_a_newer_spec_is_refused(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('Upgrade the package');

        SagaBlueprint::fromJson('{"v":99,"saga":"App\\\\Sagas\\\\OrderSaga","subject":"App\\\\Sagas\\\\OrderSubject","places":[],"initial":[],"steps":[]}');
    }

    public function test_a_blueprint_that_is_not_an_object_is_refused(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('not a JSON object');

        SagaBlueprint::fromJson('"just a string"');
    }

    public function test_an_unknown_step_kind_is_refused(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('unknown step kind');

        SagaBlueprint::fromJson(
            '{"v":1,"saga":"App\\\\Sagas\\\\OrderSaga","subject":"App\\\\Sagas\\\\OrderSubject","places":["a","b"],'
            . '"initial":["a"],"steps":[{"kind":"teleport","name":"go","from":["a"],"to":["b"]}]}',
        );
    }

    public function test_steps_must_be_a_list_of_objects(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage("'steps' must hold objects only");

        SagaBlueprint::fromJson(
            '{"v":1,"saga":"App\\\\Sagas\\\\OrderSaga","subject":"App\\\\Sagas\\\\OrderSubject","places":["a","b"],'
            . '"initial":["a"],"steps":["go"]}',
        );
    }

    public function test_a_step_missing_a_field_is_refused(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage("missing the string field 'name'");

        SagaBlueprint::fromJson(
            '{"v":1,"saga":"App\\\\Sagas\\\\OrderSaga","subject":"App\\\\Sagas\\\\OrderSubject","places":["a","b"],'
            . '"initial":["a"],"steps":[{"kind":"transition","from":["a"],"to":["b"]}]}',
        );
    }

    // ---------------------------------------------------------------- reading a generated file

    public function test_a_source_without_a_blueprint_is_not_ours(): void
    {
        self::assertNull(SagaBlueprint::fromSource('<?php final class HandWritten implements Saga {}'));
    }

    public function test_a_rendered_docblock_reads_back_as_the_same_blueprint(): void
    {
        $blueprint = $this->ordered();
        $blueprint->stamp(SagaBlueprint::RENDERER_VERSION, 'sha256:abc');

        $source = "<?php\n\n" . $blueprint->toDocblock() . "\nfinal class OrderSaga\n{\n}\n";

        $restored = SagaBlueprint::fromSource($source);

        self::assertNotNull($restored);
        self::assertSame($blueprint->toJson(), $restored->toJson());
        self::assertSame('sha256:abc', $restored->bodyHash());
    }

    public function test_a_docblock_indented_inside_a_class_reads_back_too(): void
    {
        $blueprint = $this->ordered();

        $indented = array_map(
            static fn (string $line): string => '    ' . $line,
            explode("\n", $blueprint->toDocblock()),
        );

        $source = "<?php\n\nfinal class OrderSaga\n{\n" . implode("\n", $indented) . "\n}\n";

        $restored = SagaBlueprint::fromSource($source);

        self::assertNotNull($restored);
        self::assertSame($blueprint->toJson(), $restored->toJson());
    }

    public function test_a_docblock_reads_back_from_a_file_with_crlf_line_endings(): void
    {
        // A Windows checkout — or any editor that saved the file that way —
        // turns a perfectly good generated class into one this command could
        // not read back, which is the difference between carrying on and
        // refusing to touch the author's file at all. `$` is true only before
        // a `\n`, and the `\r` sits in between.
        $blueprint = $this->ordered();
        $blueprint->stamp(SagaBlueprint::RENDERER_VERSION, 'sha256:abc');

        $source = "<?php\r\n\r\n"
            . str_replace("\n", "\r\n", $blueprint->toDocblock())
            . "\r\nfinal class OrderSaga\r\n{\r\n}\r\n";

        $restored = SagaBlueprint::fromSource($source);

        self::assertNotNull($restored);
        self::assertSame($blueprint->toJson(), $restored->toJson());
        self::assertSame('sha256:abc', $restored->bodyHash());
    }

    public function test_a_block_whose_json_is_broken_is_refused_rather_than_treated_as_absent(): void
    {
        // "I cannot read what is there" must never be answered with "so I will
        // write over it": that is how a damaged saga gets clobbered.
        $source = "<?php\n/**\n * " . SagaBlueprint::DOCBLOCK_TAG . "\n * {\"v\":1,\n * "
            . SagaBlueprint::DOCBLOCK_END . "\n */\nfinal class OrderSaga {}\n";

        $this->expectException(JsonException::class);

        SagaBlueprint::fromSource($source);
    }

    public function test_a_block_that_was_cut_in_half_is_refused_rather_than_treated_as_absent(): void
    {
        $source = "<?php\n/**\n * " . SagaBlueprint::DOCBLOCK_TAG . "\n * {\"v\":1}\n */\nfinal class OrderSaga {}\n";

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('no complete blueprint block');

        SagaBlueprint::fromSource($source);
    }
}
