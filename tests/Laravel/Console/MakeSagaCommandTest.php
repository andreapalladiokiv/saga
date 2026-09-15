<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Techork\Saga\Laravel\Console\MakeSagaCommand;
use Techork\Saga\Laravel\Console\SagaPrompter;
use Techork\Saga\Laravel\Console\SagaStepKind;
use Techork\Saga\Tests\Laravel\Console\Fixtures\ScriptedSagaPrompter;

use function array_key_exists;
use function explode;
use function is_array;
use function preg_quote;
use function sprintf;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The command is wiring, and this is where the wiring is checked.
 *
 * Everything about what a saga may be is tested elsewhere — the wizard's own
 * test drives the dialogue, the scaffolder's test drives the files. What is
 * left here is exactly what the command adds: turning a name into a namespace
 * and a directory, refusing a file it did not write, deciding whether an error
 * stops the write, and reporting what changed.
 *
 * The dialogue is substituted rather than typed, and the container is bare —
 * `Illuminate\Container\Container` with nothing bound — because that is what the
 * command claims to need. The one exception is the test that pins the `app`
 * path fallback, which binds the single key it is about.
 */
final class MakeSagaCommandTest extends TestCase
{
    private Filesystem $files;

    private Container $app;

    /** A directory that exists, holding nothing else. */
    private string $root;

    /** Where `--path` points. Deliberately not created up front. */
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->app = new Container();
        $this->root = sys_get_temp_dir() . '/saga-command-' . uniqid('', true);

        $this->files->makeDirectory($this->root);

        $this->directory = $this->root . '/sagas';
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ a clean run

    public function test_a_graph_the_wizard_built_is_written_out_and_said_to_be_written(): void
    {
        $prompter = $this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ['stepKind', null],
        ]);

        $result = $this->artisan($prompter, ['name' => 'OrderSaga']);

        $prompter->assertFinished();

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->directory . '/OrderSaga.php');
        self::assertFileExists($this->directory . '/OrderSagaListeners.php');
        self::assertFileExists($this->directory . '/OrderSubject.php');

        self::assertReported($result['output'], 'created', 'OrderSaga.php');
        self::assertReported($result['output'], 'created', 'OrderSagaListeners.php');

        $saga = $this->files->get($this->directory . '/OrderSaga.php');

        self::assertStringContainsString('final class OrderSaga implements Saga', $saga);
        self::assertStringContainsString('@saga-blueprint', $saga);
        self::assertStringContainsString("new Transition('reserve_stock', 'placed', 'reserved')", $saga);
    }

    /**
     * The one thing the command cannot write into a file, printed as code to
     * paste — with class names spelled out in full.
     *
     * Full means with the leading backslash, and the assertions below say so
     * on purpose. `App\Sagas\OrderSaga` pasted into a provider is not a name
     * that fails to resolve; it is one that resolves wrongly, to
     * `App\Providers\App\Sagas\OrderSaga`, in a line that still reads as if it
     * were right. Every name the snippet does not own — Illuminate, Symfony,
     * Techork — is already written absolutely for the same reason.
     */
    public function test_registration_is_printed_rather_than_written_into_someone_elses_provider(): void
    {
        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ['stepKind', null],
        ]), ['name' => 'OrderSaga']);

        self::assertStringContainsString(
            '\App\Sagas\OrderSagaListeners::register(',
            $result['output'],
        );
        self::assertStringContainsString(
            '$this->app->make(\App\Sagas\OrderSaga::class)->definition()',
            $result['output'],
        );
        self::assertStringContainsString(
            'new \Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy(\App\Sagas\OrderSubject::class)',
            $result['output'],
        );
    }

    /**
     * A second run carrying on from the file on disk.
     *
     * Two things are being pinned at once, and the second is the reason it is
     * worth a test of its own: the blueprint is read back out of the generated
     * class — so the wizard is not asked where the saga starts a second time —
     * and every file comes out byte-identical, which is what the `unchanged`
     * lines exist to show.
     */
    public function test_a_run_that_adds_nothing_reports_unchanged_rather_than_rewriting(): void
    {
        $first = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ['stepKind', null],
        ]), ['name' => 'OrderSaga']);

        self::assertSame(Command::SUCCESS, $first['code']);

        $before = $this->files->get($this->directory . '/OrderSaga.php');

        // No `initialPlaces`: the graph says where it starts.
        $prompter = $this->script([['stepKind', null]]);
        $second = $this->artisan($prompter, ['name' => 'OrderSaga']);

        $prompter->assertFinished();

        self::assertSame(Command::SUCCESS, $second['code']);
        self::assertStringNotContainsString('created', $second['output']);
        self::assertStringNotContainsString('updated', $second['output']);
        self::assertReported($second['output'], 'unchanged', 'OrderSaga.php');

        self::assertSame($before, $this->files->get($this->directory . '/OrderSaga.php'));
    }

    // ------------------------------------------------------------------ what it refuses

    public function test_a_name_that_cannot_be_a_class_name_is_refused_before_anything_is_made(): void
    {
        $prompter = $this->script([]);
        $result = $this->artisan($prompter, ['name' => 'Order Saga']);

        self::assertSame(Command::FAILURE, $result['code']);
        self::assertStringContainsString('cannot be a class name', $result['output']);
        self::assertStringContainsString('Order Saga', $result['output']);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * The generator's one outright refusal, and the reason it is not `--force`.
     *
     * A saga written by hand is not a draft of ours, and there is nothing about
     * it this command could preserve — it has no blueprint to carry on from and
     * no markers to write between. Offering a flag would suggest otherwise.
     */
    public function test_a_saga_this_command_did_not_write_is_refused_and_left_untouched(): void
    {
        $this->files->makeDirectory($this->directory);

        $handWritten = "<?php\n\nnamespace App\\Sagas;\n\nfinal class OrderSaga\n{\n}\n";
        $this->files->put($this->directory . '/OrderSaga.php', $handWritten);

        $result = $this->artisan($this->script([]), ['name' => 'OrderSaga']);

        self::assertSame(Command::FAILURE, $result['code']);
        self::assertStringContainsString('carries no blueprint', $result['output']);
        self::assertStringContainsString('@saga-blueprint', $result['output']);

        self::assertSame($handWritten, $this->files->get($this->directory . '/OrderSaga.php'));
        self::assertFileDoesNotExist($this->directory . '/OrderSagaListeners.php');
    }

    /**
     * A hand edit to `definition()` is the whole reason the graph is stored.
     *
     * Refused first, and refused before the wizard asks anything — there is
     * nowhere for a half-built graph to live except the session that built it.
     * Then `--force`, which is the one thing that makes the edit something the
     * author agreed to lose.
     */
    public function test_a_hand_edit_to_definition_is_refused_until_forced(): void
    {
        $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'reserve_stock', ['placed'], ['reserved']),
            ['stepKind', null],
        ]), ['name' => 'OrderSaga']);

        $path = $this->directory . '/OrderSaga.php';
        $edited = str_replace("'placed', 'reserved'", "'placed', 'held'", $this->files->get($path));

        self::assertStringContainsString("'placed', 'held'", $edited);
        $this->files->put($path, $edited);

        // Nothing is scripted, because nothing should be asked.
        $refused = $this->artisan($this->script([]), ['name' => 'OrderSaga']);

        self::assertSame(Command::FAILURE, $refused['code']);
        self::assertStringContainsString('edited by hand', $refused['output']);
        self::assertStringContainsString('--force', $refused['output']);
        self::assertStringContainsString('Nothing was written', $refused['output']);
        self::assertSame($edited, $this->files->get($path));

        $forced = $this->artisan($this->script([['stepKind', null]]), [
            'name' => 'OrderSaga',
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $forced['code']);
        self::assertStringContainsString('--force overwrote that edit', $forced['output']);

        $restored = $this->files->get($path);

        self::assertStringContainsString("'placed', 'reserved'", $restored);
        self::assertStringNotContainsString("'placed', 'held'", $restored);
    }

    /**
     * A step the wizard could not record is a step the graph does not have, and
     * a graph with somewhere to start and nowhere to go is not written at all.
     */
    public function test_a_graph_that_does_not_hold_together_is_not_written(): void
    {
        $prompter = $this->script([
            ['initialPlaces', ['placed']],
            ['stepKind', SagaStepKind::Signal],
            ['stepName', 'pay'],
            ['fromPlaces', ['placed']],
            ['toPlaces', ['paid']],
            // Outside the namespace the command writes into, so no DTO is
            // offered and the step cannot be recorded.
            ['awaitedClass', 'Other\Ns\Payment'],
            ['stepKind', null],
        ]);

        $result = $this->artisan($prompter, ['name' => 'OrderSaga']);

        $prompter->assertFinished();

        self::assertSame(Command::FAILURE, $result['code']);
        self::assertTrue($prompter->hasSaid('not in App\Sagas'));
        self::assertStringContainsString('the graph does not hold together', $result['output']);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    // ------------------------------------------------------------------ names and places

    public function test_a_name_may_carry_a_subdirectory(): void
    {
        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'charge', ['placed'], ['charged']),
            ['stepKind', null],
        ]), ['name' => 'Billing\ChargeSaga']);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->directory . '/Billing/ChargeSaga.php');
        self::assertFileExists($this->directory . '/Billing/ChargeSubject.php');

        self::assertStringContainsString(
            'namespace App\Sagas\Billing;',
            $this->files->get($this->directory . '/Billing/ChargeSaga.php'),
        );
        self::assertStringContainsString(
            'App\Sagas\Billing\ChargeSaga::class',
            $result['output'],
        );
    }

    public function test_the_namespace_can_be_given_instead_of_taken_from_the_config(): void
    {
        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'charge', ['placed'], ['charged']),
            ['stepKind', null],
        ]), ['name' => 'ChargeSaga', '--namespace' => '\Shop\Workflows']);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertStringContainsString(
            'namespace Shop\Workflows;',
            $this->files->get($this->directory . '/ChargeSaga.php'),
        );
    }

    /**
     * Where the files go when nothing says: the application's own `app`
     * directory, which is the only thing the command asks the container for.
     */
    public function test_the_directory_falls_back_to_the_app_path(): void
    {
        $this->app->bind('path.app', fn (): string => $this->root);

        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'charge', ['placed'], ['charged']),
            ['stepKind', null],
        ]), ['name' => 'ChargeSaga', '--path' => null], $this->app);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->root . '/Sagas/ChargeSaga.php');
    }

    public function test_the_config_is_read_when_no_option_says_otherwise(): void
    {
        $app = new Container();
        $app->instance('config', self::config([
            'saga' => ['generator' => ['path' => $this->root . '/from-config', 'namespace' => 'Shop\Sagas']],
        ]));

        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'charge', ['placed'], ['charged']),
            ['stepKind', null],
        ]), ['name' => 'ChargeSaga', '--path' => null], $app);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->root . '/from-config/ChargeSaga.php');
        self::assertStringContainsString(
            'namespace Shop\Sagas;',
            $this->files->get($this->root . '/from-config/ChargeSaga.php'),
        );
    }

    public function test_an_option_wins_over_the_config(): void
    {
        $app = new Container();
        $app->instance('config', self::config([
            'saga' => ['generator' => ['path' => $this->root . '/from-config', 'namespace' => 'Shop\Sagas']],
        ]));

        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'charge', ['placed'], ['charged']),
            ['stepKind', null],
        ]), ['name' => 'ChargeSaga', '--namespace' => 'App\Sagas'], $app);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->directory . '/ChargeSaga.php');
        self::assertFileDoesNotExist($this->root . '/from-config/ChargeSaga.php');
    }

    /**
     * The published config's own default, which is `null`.
     *
     * Worth a test of its own: a config value is `mixed` by nature, and the
     * shipped `config/saga.php` ships a null path precisely so that the
     * fallback — not the config — decides where a stock application writes. A
     * command that trusted the key would write to `/ChargeSaga.php`.
     */
    public function test_a_config_value_that_is_not_a_path_is_ignored(): void
    {
        $app = new Container();
        $app->instance('config', self::config([
            'saga' => ['generator' => ['path' => null, 'namespace' => null]],
        ]));
        $app->bind('path.app', fn (): string => $this->root);

        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Transition, 'charge', ['placed'], ['charged']),
            ['stepKind', null],
        ]), ['name' => 'ChargeSaga', '--path' => null], $app);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->root . '/Sagas/ChargeSaga.php');
        self::assertStringContainsString(
            'namespace App\Sagas;',
            $this->files->get($this->root . '/Sagas/ChargeSaga.php'),
        );
    }

    // ------------------------------------------------------------------ the DTOs

    /**
     * What the wizard agreed to when it said a class does not exist yet.
     *
     * The two halves of this are in different layers — the wizard records the
     * promise, the scaffolder turns it into a file — and this is the only place
     * they are checked against each other.
     */
    public function test_a_payload_the_wizard_agreed_to_write_is_written(): void
    {
        $result = $this->artisan($this->script([
            ['initialPlaces', ['placed']],
            ...self::step(SagaStepKind::Signal, 'pay', ['placed'], ['paid'], [
                ['awaitedClass', 'App\Sagas\PaymentCaptured'],
                ['confirm', true],
            ]),
            ['stepKind', null],
        ]), ['name' => 'OrderSaga']);

        self::assertSame(Command::SUCCESS, $result['code']);
        self::assertFileExists($this->directory . '/PaymentCaptured.php');
        self::assertStringContainsString(
            'namespace App\Sagas;',
            $this->files->get($this->directory . '/PaymentCaptured.php'),
        );
        self::assertStringContainsString(
            'new Signal(',
            $this->files->get($this->directory . '/OrderSaga.php'),
        );
    }

    // ------------------------------------------------------------------ machinery

    /**
     * Run the command as the artisan kernel would, minus the kernel.
     *
     * `handle()` and never `run()`: `run()` reaches for `$this->laravel`, which
     * is never set here, and configures prompts for a terminal there is none of.
     * `handle()` needs the input and the output and nothing else.
     *
     * `BufferedOutput` and not `BufferedConsoleOutput`: the latter writes
     * through to the real STDOUT, which PHPUnit's strict-output mode reports as
     * a test that printed something.
     *
     * @param  array<string, mixed>  $params
     * @return array{code: int, output: string}
     */
    private function artisan(ScriptedSagaPrompter $prompter, array $params, ?ContainerContract $app = null): array
    {
        $command = $this->command($prompter, $app);
        $input = new ArrayInput(['--path' => $this->directory, ...$params], $command->getDefinition());
        $output = new BufferedOutput();

        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        $code = $command->handle();

        return ['code' => $code, 'output' => $output->fetch()];
    }

    /**
     * The command with its one seam substituted.
     *
     * Extending rather than binding, because that is how a Laravel command is
     * meant to be opened up and because the alternative — a container that can
     * answer for `ConsoleSagaPrompter` — would be a container this command does
     * not otherwise need.
     */
    private function command(ScriptedSagaPrompter $prompter, ?ContainerContract $app = null): MakeSagaCommand
    {
        return new class($app ?? $this->app, $this->files, $prompter) extends MakeSagaCommand {
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
    }

    /**
     * @param  list<array{string, mixed}>  $script
     */
    private function script(array $script): ScriptedSagaPrompter
    {
        return new ScriptedSagaPrompter($script);
    }

    /**
     * A config repository, as far as the command can tell.
     *
     * It asks for a dotted key and reads the answer, and nothing more —
     * `illuminate/config` is not a dependency of this package, so requiring the
     * real one to test four lines of reading would be requiring it for the test
     * alone.
     *
     * @param  array<string, mixed>  $values
     */
    private static function config(array $values): object
    {
        return new class($values) {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $key, mixed $default = null): mixed
            {
                $value = $this->values;

                foreach (explode('.', $key) as $segment) {
                    if (! is_array($value) || ! array_key_exists($segment, $value)) {
                        return $default;
                    }

                    $value = $value[$segment];
                }

                return $value;
            }
        };
    }

    /**
     * The questions one step costs, in the order the wizard asks them.
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
     * One line of the run's own account of what it did to the files.
     *
     * Matched as a whole line, because the words are also in the messages: a run
     * that refused says nothing about `created`, and a substring search would
     * find those letters in a path or a class name eventually.
     */
    private static function assertReported(string $output, string $status, string $file): void
    {
        self::assertMatchesRegularExpression(
            '~^' . preg_quote($status, '~') . '\s+\S*' . preg_quote($file, '~') . '$~m',
            $output,
            sprintf('The run did not report "%s" for %s. It said: %s', $status, $file, $output),
        );
    }
}
