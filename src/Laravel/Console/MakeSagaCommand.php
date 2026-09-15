<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Techork\Saga\SagaException;

use function getcwd;
use function is_object;
use function is_string;
use function ltrim;
use function method_exists;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * `php artisan make:saga OrderSaga` — the saga, one step at a time.
 *
 * The command itself is wiring and no rules. What a step may be is
 * {@see SagaStep}'s; what a graph may be is {@see SagaBlueprint::validate()}'s;
 * which files that implies is {@see SagaScaffolder}'s; what to ask is
 * {@see SagaWizard}'s. This asks for a name, works out where the files go, and
 * writes what came back.
 *
 * It is not `final` for the same reason Laravel's own commands are open: the
 * dialogue arrives through {@see makePrompter()}, and a test substitutes it by
 * extending the class rather than by binding anything. Nothing else is meant to
 * be overridden.
 *
 * `--force` means one thing only: the `definition()` in the file was edited by
 * hand, and this run overwrote it. It is not a licence to clobber a saga this
 * command did not write — that file is refused outright, and stays refused.
 */
class MakeSagaCommand extends Command
{
    public const DEFAULT_NAMESPACE = 'App\Sagas';

    /**
     * @var string
     */
    protected $signature = 'make:saga {name} {--path=} {--namespace=} {--force}';

    /**
     * @var string
     */
    protected $description = 'Build a saga a step at a time, and write the class, its listeners and its DTOs';

    /**
     * The container is taken as `Illuminate\Contracts\Container\Container`
     * rather than as the framework application, for the same reason the service
     * provider does it: nothing here needs the application, only the two things
     * it can answer — whether a config repository is bound, and where `app`
     * lives. That also makes the command constructible against a bare
     * container, which is how it is tested.
     */
    public function __construct(
        private readonly Container $app,
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $target = $this->target();
            $files = $this->existing($target['directory']);
            $existing = $files[self::shortName($target['saga']) . '.php'] ?? null;

            $blueprint = $existing === null
                ? new SagaBlueprint($target['saga'], $target['subject'])
                : $this->carryOn($existing, $target['saga']);
        } catch (JsonException|SagaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $force = $this->option('force') === true;
        $scaffolder = new SagaScaffolder();

        if ($existing !== null) {
            // A file that cannot be written is found out before a single
            // question is asked. A `definition()` edited by hand, or a marker
            // someone deleted, is the author's to deal with — and finding out
            // after a dozen answers would throw them all away, because there is
            // nowhere for a wizard's half-built graph to live except in the
            // session that built it.
            $preflight = $scaffolder->plan($blueprint, $files, $force);

            if ($preflight->isBlocked()) {
                $this->report($preflight);
                $this->error('Nothing was written. Settle that, then run the command again.');

                return self::FAILURE;
            }
        }

        $blueprint = (new SagaWizard($this->makePrompter(), $blueprint))->run();
        $plan = $scaffolder->plan($blueprint, $files, $force);

        $this->report($plan);

        if ($plan->isBlocked()) {
            $this->error('Nothing was written: the graph does not hold together yet.');

            return self::FAILURE;
        }

        $this->write($plan, $target['directory']);
        $this->registration($blueprint);

        return self::SUCCESS;
    }

    /**
     * The voice the wizard speaks through.
     *
     * The only seam this command has, and it exists because the alternative is
     * a command that can only be tested by feeding a terminal.
     */
    protected function makePrompter(): SagaPrompter
    {
        return new ConsoleSagaPrompter($this);
    }

    /**
     * Where the saga goes and what it is called, all of it checked.
     *
     * A name may carry a subdirectory — `Billing\ChargeSaga` — which is the
     * only reason this is not two lines. Nothing else about the name is
     * interpreted: it is a class name, and `--namespace` or the config says
     * what it hangs off.
     *
     * @return array{saga: string, subject: string, directory: string}
     *
     * @throws SagaException when the name cannot be a class name
     */
    private function target(): array
    {
        $name = $this->argument('name');

        if (! is_string($name) || trim($name, '\\') === '') {
            throw new SagaException('A saga needs a name: php artisan make:saga OrderSaga');
        }

        // A leading backslash means "from the root", which is not a place this
        // command writes to. Trimmed rather than refused, because it is how
        // people write a class name out of habit.
        $name = trim($name, '\\');

        if (preg_match(SagaStep::CLASS_PATTERN, $name) !== 1) {
            throw new SagaException(sprintf(
                "'%s' cannot be a class name. Give the name the saga class itself will have — "
                . 'OrderSaga, or Billing\ChargeSaga to put it in a subdirectory — and let '
                . '--namespace or config/saga.php say what it hangs off.',
                $name,
            ));
        }

        $saga = $this->namespace() . '\\' . $name;

        return [
            'saga' => $saga,
            'subject' => self::namespaceOf($saga) . '\\' . self::subjectClass(self::shortName($saga)),
            'directory' => $this->directory() . self::subPath($name),
        ];
    }

    /**
     * The graph to carry on from.
     *
     * A file this command did not write is refused, and refused before anything
     * else happens. It is the one place where the generator declines outright
     * rather than reporting: a saga written by hand is not a draft of ours, and
     * there is no flag that would make overwriting it a reasonable thing to
     * offer.
     *
     * @throws JsonException|SagaException when the block is there but unreadable
     */
    private function carryOn(string $source, string $saga): SagaBlueprint
    {
        $blueprint = SagaBlueprint::fromSource($source);

        if ($blueprint === null) {
            throw new SagaException(sprintf(
                '%s already exists and carries no blueprint, so this command did not write it. Nothing '
                . 'here rewrites a saga someone wrote by hand: move it aside, name another saga, or '
                . 'add a %s block to it to say it is one of ours.',
                self::shortName($saga),
                SagaBlueprint::DOCBLOCK_TAG,
            ));
        }

        return $blueprint;
    }

    /**
     * Everything the generator has to say, said once.
     *
     * Errors and warnings go to different streams because they mean different
     * things: an error is why nothing was written, a warning is something the
     * author should know about a file that was.
     */
    private function report(ScaffoldingPlan $plan): void
    {
        foreach ($plan->issues as $issue) {
            if ($issue->isError) {
                $this->error($issue->message);

                continue;
            }

            $this->warn($issue->message);
        }
    }

    /**
     * Write the plan out, and say what changed.
     *
     * A file whose contents are already what they should be is left alone and
     * said to be unchanged, rather than rewritten. On an iteration that added
     * nothing, every line then reads `unchanged`, which is how the author knows
     * the run did not quietly touch anything.
     */
    private function write(ScaffoldingPlan $plan, string $directory): void
    {
        $this->files->ensureDirectoryExists($directory);

        foreach ($plan->files as $file) {
            $path = $directory . '/' . $file->name;
            $existing = $this->files->exists($path) ? $this->files->get($path) : null;

            if ($existing === $file->contents) {
                $this->line(sprintf('unchanged  %s', $path));

                continue;
            }

            $this->files->put($path, $file->contents);
            $this->line(sprintf('%s  %s', $existing === null ? 'created   ' : 'updated   ', $path));
        }
    }

    /**
     * The one thing that cannot be written into a file of its own.
     *
     * Registration belongs in a provider the author already has, and editing
     * someone else's file is exactly what a generator must not do — so it is
     * printed instead, as the code to paste, with class names written out in
     * full so that nothing here depends on what the file it lands in imports.
     */
    private function registration(SagaBlueprint $blueprint): void
    {
        $saga = self::qualified($blueprint->saga());
        $subject = self::qualified($blueprint->subject());
        $listeners = self::qualified(
            self::namespaceOf($blueprint->saga()) . '\\' . self::shortName($blueprint->saga()) . 'Listeners',
        );

        $this->newLine();
        $this->line('Still yours to do — in a provider\'s boot(), or wherever your workflows are registered:');
        $this->newLine();

        foreach ([
            sprintf('    %s::register($this->app->make(\Illuminate\Contracts\Events\Dispatcher::class));', $listeners),
            '',
            '    $this->app->make(\Symfony\Component\Workflow\Registry::class)->addWorkflow(',
            '        new \Symfony\Component\Workflow\Workflow(',
            sprintf('            $this->app->make(%s::class)->definition(),', $saga),
            '            $this->app->make(\Techork\Saga\SagaMarkingStore::class),',
            '            $this->app->make(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),',
            sprintf('            %s::class,', $saga),
            '        ),',
            sprintf('        new \Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy(%s::class),', $subject),
            '    );',
        ] as $line) {
            $this->line($line);
        }

        $this->newLine();
    }

    /**
     * The namespace to write into: the option, then the config, then the default.
     *
     * The config is read defensively — a bare container has no `config` binding
     * at all, and the published `config/saga.php` is a convenience rather than
     * a requirement of the command.
     */
    private function namespace(): string
    {
        $option = $this->option('namespace');

        if (is_string($option) && trim($option, '\\') !== '') {
            return trim($option, '\\');
        }

        $configured = $this->config('saga.generator.namespace');

        return is_string($configured) && trim($configured, '\\') !== ''
            ? trim($configured, '\\')
            : self::DEFAULT_NAMESPACE;
    }

    /**
     * The directory to write into: the option, then the config, then `app/Sagas`.
     *
     * Each value is trimmed of its trailing slashes before it is judged, and
     * that order is the point: `--path=/` is the empty string once the slash
     * comes off, and an empty directory handed to `ensureDirectoryExists()`
     * reaches an unsuppressed `mkdir('')`. Falling through to the next source
     * is also the honest reading — a path of nothing but slashes names nothing
     * the author meant.
     */
    private function directory(): string
    {
        $option = $this->option('path');
        $option = is_string($option) ? rtrim($option, '/') : '';

        if ($option !== '') {
            return $option;
        }

        $configured = $this->config('saga.generator.path');
        $configured = is_string($configured) ? rtrim($configured, '/') : '';

        if ($configured !== '') {
            return $configured;
        }

        $app = $this->app->bound('path.app') ? $this->app->make('path.app') : null;
        $app = is_string($app) ? rtrim($app, '/') : '';

        if ($app !== '') {
            return $app . '/Sagas';
        }

        $cwd = getcwd();

        return ($cwd === false ? '.' : $cwd) . '/app/Sagas';
    }

    private function config(string $key): mixed
    {
        if (! $this->app->bound('config')) {
            return null;
        }

        $config = $this->app->make('config');

        if (! is_object($config) || ! method_exists($config, 'get')) {
            return null;
        }

        return $config->get($key);
    }

    /**
     * The target directory as it is on disk.
     *
     * Read whole, by file name, and handed to the generator as-is: it looks up
     * the three or four names it cares about and ignores the rest, and deciding
     * here which files matter would be the same knowledge written in a second
     * place.
     *
     * @return array<string, string>
     */
    private function existing(string $directory): array
    {
        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $files = [];

        foreach ($this->files->files($directory) as $file) {
            $files[$file->getFilename()] = $this->files->get($file->getPathname());
        }

        return $files;
    }

    /**
     * The subject DTO's class, named after the saga: `OrderSaga` -> `OrderSubject`.
     *
     * A name that does not end in `Saga` gets the suffix all the same. There is
     * nothing to strip, and a subject named after its saga is what the author
     * will look for either way.
     */
    private static function subjectClass(string $class): string
    {
        $base = str_ends_with($class, 'Saga') && strlen($class) > 4 ? substr($class, 0, -4) : $class;

        return $base . 'Subject';
    }

    /**
     * The subdirectory a name asks for, as a path: `Billing\ChargeSaga` -> `/Billing`.
     */
    private static function subPath(string $name): string
    {
        $namespace = self::namespaceOf($name);

        return $namespace === '' ? '' : '/' . str_replace('\\', '/', $namespace);
    }

    /**
     * A class name written absolutely, for a file that is not ours.
     *
     * Everything the registration snippet names has to survive being pasted
     * into a provider, where a bare `App\Sagas\OrderSaga` is resolved against
     * that file's own namespace and becomes `App\Providers\App\Sagas\OrderSaga`
     * — a class that does not exist, in a line that still reads correctly. The
     * leading backslash is what makes it absolute; the `ltrim` is so that a
     * name already written that way is not given a second one.
     */
    private static function qualified(string $class): string
    {
        return '\\' . ltrim($class, '\\');
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
