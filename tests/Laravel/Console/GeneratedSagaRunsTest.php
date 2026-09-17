<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Events\Dispatcher as LaravelDispatcher;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\InMemorySagaQueue;
use Techork\Saga\InMemorySagaStateRepository;
use Techork\Saga\InProcessSagaLock;
use Techork\Saga\Laravel\Console\MakeSagaCommand;
use Techork\Saga\Laravel\Console\SagaPrompter;
use Techork\Saga\Laravel\Console\SagaStepKind;
use Techork\Saga\Laravel\LaravelEventDispatcherAdapter;
use Techork\Saga\Saga;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\SagaNotWaitingException;
use Techork\Saga\SagaRunner;
use Techork\Saga\SagaState;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ScriptedSagaPrompter;

use function class_exists;
use function method_exists;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The acceptance test: what the wizard builds is a saga that runs.
 *
 * Everything else in this directory checks one layer against a description of
 * itself — the wizard against a script, the scaffolder against a plan, the
 * command against its own output. This one checks nothing against a
 * description. It drives the real command, `require`s the files it wrote, and
 * hands the graph to a real {@see SagaRunner} over a real registry and a real
 * event dispatcher, so the only thing that can make it pass is generated code
 * that PHP will actually load and Symfony will actually run.
 *
 * It is also the test that catches the mistake the whole design is arranged
 * around: a `Signal` matches its payload with `instanceof` against the class
 * name written into the file, so a graph that reads perfectly and names a class
 * nobody wrote parks forever. Here the saga is woken by an instance of the
 * class the wizard named, and by nothing else.
 *
 * The saga is generated once for the process, because the class names the
 * generator writes are fixed by the saga's name and a second generation would
 * redeclare them. What is per-test is the runtime: a fresh repository, queue,
 * registry and dispatcher each time, over the same loaded classes.
 */
final class GeneratedSagaRunsTest extends TestCase
{
    /**
     * Set once by {@see generated()}, and dropped with the directory in
     * {@see tearDownAfterClass()}.
     *
     * @var array{saga: string, subject: string, payload: string, listeners: string}|null
     */
    private static ?array $classes = null;

    private static string $directory = '';

    private InMemorySagaStateRepository $repository;

    private InMemorySagaQueue $queue;

    private LaravelDispatcher $events;

    public static function tearDownAfterClass(): void
    {
        if (self::$directory !== '') {
            (new Filesystem())->deleteDirectory(self::$directory);
        }

        self::$directory = '';
        self::$classes = null;

        parent::tearDownAfterClass();
    }

    /**
     * Generated code, loaded and run.
     *
     * The graph is `placed --reserve_stock--> reserved --pay--> paid`, where
     * `pay` is a Signal awaiting a payload the command wrote in the same run.
     * That is the smallest graph that exercises the three things the generator
     * has to get right: an ordinary transition the runner fires by itself, a
     * Signal it must never fire, and a class the graph names that the graph
     * caused to exist.
     */
    public function test_a_generated_saga_parks_and_is_woken_by_the_payload_the_wizard_named(): void
    {
        $generated = self::generated();
        $saga = self::instantiate($generated['saga']);
        $subject = self::instantiate($generated['subject']);
        $payload = self::declared($generated['payload']);

        $runner = $this->runner($generated);

        $runner->start($saga, 'order-1', $subject);
        $this->drain($runner, $saga);

        // `reserve_stock` was queued and fired; `pay` is a Signal, so the saga
        // is parked instead of having run past it.
        self::assertTrue($this->queue->isEmpty(), 'a Signal must never be queued');
        self::assertSame(['reserved' => 1], $this->state('order-1')->marking);

        // An unrelated payload is refused by name — which is how the generated
        // `awaits` class is shown to be the one gating delivery, rather than a
        // name that merely reads well.
        try {
            $runner->signal($saga, 'order-1', new stdClass());

            self::fail('A saga parked on a Signal must not be woken by an unrelated payload.');
        } catch (SagaNotWaitingException $e) {
            self::assertStringContainsString($payload, $e->getMessage());
        }

        self::assertSame(['reserved' => 1], $this->state('order-1')->marking, 'it stays parked');

        // The payload it does accept, through the listener the command wrote.
        $runner->signal($saga, 'order-1', new $payload());

        self::assertNull($this->repository->load('order-1'), 'paid is terminal, so the row goes');
    }

    /**
     * The listeners file, against the events the runner actually emits.
     *
     * The generator writes event names it never gets to see used; the runner
     * emits names it never gets to see written. This is where the two are held
     * against each other: every key the generated map was supposed to carry has
     * to be on the dispatcher afterwards, under exactly the string the runner
     * builds from the saga class and the step name.
     */
    public function test_the_generated_listeners_are_attached_to_the_events_the_runner_emits(): void
    {
        $generated = self::generated();
        $saga = $generated['saga'];

        $this->runner($generated);

        self::assertNotEmpty($this->events->getListeners("workflow.$saga.transition.reserve_stock"));
        self::assertNotEmpty($this->events->getListeners("workflow.$saga.transition.pay"));
        self::assertNotEmpty($this->events->getListeners("saga.$saga.compensate.reserve_stock"));
        self::assertNotEmpty($this->events->getListeners("saga.$saga.compensate.pay"));

        // No Call runs from this graph, and no step leaves a place another step
        // also leaves, so there is nothing for a guard to block and none was
        // written. A guard that blocks nothing is a guard that does nothing.
        self::assertSame([], $this->events->getListeners("workflow.$saga.guard.reserve_stock"));
    }

    /**
     * Rollback, driven by the same generated file.
     *
     * The compensate listeners the command writes are no-ops on purpose — the
     * saga has to run end to end while its author is still iterating on the
     * graph — so what is accepted here is that they are wired well enough for
     * the runner's rollback to complete: nothing throws, the steps unwind, and
     * the row is gone.
     */
    public function test_a_generated_saga_rolls_back_a_step_it_had_taken(): void
    {
        $generated = self::generated();
        $saga = self::instantiate($generated['saga']);

        $runner = $this->runner($generated);

        $runner->start($saga, 'order-2', self::instantiate($generated['subject']));
        $this->drain($runner, $saga);

        self::assertSame(['reserved' => 1], $this->state('order-2')->marking);

        $errors = $runner->compensateAndDelete($saga, 'order-2', 'pay');

        self::assertSame([], $errors, 'the generated compensate listeners must not throw');
        self::assertNull($this->repository->load('order-2'));
    }

    // ------------------------------------------------------------------ the runtime

    /**
     * Everything the printed registration snippet builds, built.
     *
     * Assembled here rather than pasted from the snippet, because the snippet
     * belongs in an application's provider and this is a test. What has to be
     * the same is the wiring, and it is: the listeners are registered on an
     * Illuminate dispatcher, that dispatcher reaches the workflow through the
     * adapter, and the workflow is named for the saga class — which is the name
     * the listener keys are built from, and the name the runner checks.
     *
     * @param  array{saga: string, subject: string, payload: string, listeners: string}  $generated
     */
    private function runner(array $generated): SagaRunner
    {
        $this->repository = new InMemorySagaStateRepository();
        $this->queue = new InMemorySagaQueue();
        $this->events = new LaravelDispatcher(new Container());

        $dispatcher = new LaravelEventDispatcherAdapter($this->events);
        $markingStore = new SagaMarkingStore();
        $registry = new Registry();

        $listeners = $generated['listeners'];

        if (! method_exists($listeners, 'register')) {
            self::fail(sprintf('The generated %s has no register().', $listeners));
        }

        $listeners::register($this->events);

        $saga = self::instantiate($generated['saga']);

        $registry->addWorkflow(
            new Workflow(
                $saga->definition(),
                $markingStore,
                $dispatcher,
                self::declared($generated['saga']),
            ),
            new InstanceOfSupportStrategy(self::declared($generated['subject'])),
        );

        return new SagaRunner(
            $this->repository,
            $this->queue,
            $dispatcher,
            $registry,
            $markingStore,
            new InProcessSagaLock(),
        );
    }

    private function drain(SagaRunner $runner, Saga $saga): void
    {
        while (($message = $this->queue->pop()) !== null) {
            $runner->run($saga, $message['id'], $message['transition']);
        }
    }

    private function state(string $id): SagaState
    {
        $state = $this->repository->load($id);

        if ($state === null) {
            self::fail(sprintf('Saga %s has no row, and the test expected to find it parked.', $id));
        }

        return $state;
    }

    // ------------------------------------------------------------------ generation

    /**
     * The command run once, its files required once, for the whole process.
     *
     * Requiring in dependency order is also the syntax check: a file that does
     * not parse cannot be loaded, and a class the graph names that no file
     * declares fails at {@see declared()} rather than somewhere in the runtime.
     *
     * @return array{saga: string, subject: string, payload: string, listeners: string}
     */
    private static function generated(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        $namespace = 'Techork\Saga\Tests\Laravel\Console\Generated';
        $files = new Filesystem();
        $directory = sys_get_temp_dir() . '/saga-runs-' . uniqid('', true);

        $files->makeDirectory($directory);

        self::$directory = $directory;
        self::$classes = [
            'saga' => $namespace . '\\OrderSaga',
            'subject' => $namespace . '\\OrderSubject',
            'payload' => $namespace . '\\PaymentCaptured',
            'listeners' => $namespace . '\\OrderSagaListeners',
        ];

        $prompter = new ScriptedSagaPrompter([
            ['initialPlaces', ['placed']],
            ['stepKind', SagaStepKind::Transition],
            ['stepName', 'reserve_stock'],
            ['fromPlaces', ['placed']],
            ['toPlaces', ['reserved']],
            ['stepKind', SagaStepKind::Signal],
            ['stepName', 'pay'],
            ['fromPlaces', ['reserved']],
            ['toPlaces', ['paid']],
            ['awaitedClass', self::$classes['payload']],
            ['confirm', true],
            ['stepKind', null],
        ]);

        $command = new class(new Container(), $files, $prompter) extends MakeSagaCommand {
            public function __construct(
                ContainerContract $app,
                Filesystem $files,
                private readonly SagaPrompter $scripted,
            ) {
                parent::__construct($app, $files);
            }

            protected function makePrompter(): SagaPrompter
            {
                return $this->scripted;
            }
        };

        $input = new ArrayInput([
            'name' => 'OrderSaga',
            '--path' => $directory,
            '--namespace' => $namespace,
        ], $command->getDefinition());
        $output = new BufferedOutput();

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        $code = $command->handle();

        self::assertSame(Command::SUCCESS, $code, "The command refused:\n" . $output->fetch());
        $prompter->assertFinished();

        require $directory . '/OrderSubject.php';
        require $directory . '/PaymentCaptured.php';
        require $directory . '/OrderSagaListeners.php';
        require $directory . '/OrderSaga.php';

        return self::$classes;
    }

    // ------------------------------------------------------------------ narrowing

    /**
     * The class the generated files were supposed to declare.
     *
     * @return class-string
     */
    private static function declared(string $class): string
    {
        if (! class_exists($class)) {
            self::fail(sprintf('The generated files did not declare %s.', $class));
        }

        return $class;
    }

    private static function instantiate(string $class): object
    {
        $declared = self::declared($class);

        return new $declared();
    }
}
