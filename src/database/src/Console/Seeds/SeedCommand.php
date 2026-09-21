<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Seeds;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Seeder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'db:seed')]
class SeedCommand extends Command
{
    use ConfirmableTrait;
    use Prohibitable;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'db:seed
                    {class? : The class name of the root seeder}
                    {--class=Database\Seeders\DatabaseSeeder : The class name of the root seeder}
                    {--database= : The database connection to seed}
                    {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     */
    protected string $description = 'Seed the database with records';

    /**
     * The connection resolver instance.
     */
    protected ConnectionResolverInterface $resolver;

    /**
     * Create a new database seed command instance.
     */
    public function __construct(ConnectionResolverInterface $resolver)
    {
        parent::__construct();

        $this->resolver = $resolver;
    }

    /**
     * Execute the console command.
     *
     * The seed connection is applied via coroutine Context, mirroring the
     * pattern used by Migrator and DatabaseManager::usingConnection(). No
     * worker-global state is mutated; concurrent coroutines are unaffected.
     */
    public function handle(): int
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->components->info('Seeding database.');

        $previousContext = CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        try {
            CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $this->getDatabase());

            $seeder = $this->getSeeder();

            $requestedClass = $this->input->getArgument('class') ?? $this->input->getOption('class');

            $shouldReportProgress = ! in_array($requestedClass, [
                'Database\Seeders\DatabaseSeeder', 'DatabaseSeeder',
            ], true);

            if ($shouldReportProgress) {
                $this->components->twoColumnDetail(
                    get_class($seeder),
                    '<fg=yellow;options=bold>RUNNING</>'
                );
            }

            $startTime = microtime(true);

            Model::unguarded(function () use ($seeder) {
                $seeder->__invoke();
            });
        } finally {
            if ($previousContext === null) {
                CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previousContext);
            }
        }

        if ($shouldReportProgress) {
            $runTime = number_format((microtime(true) - $startTime) * 1000);

            $this->components->twoColumnDetail(
                get_class($seeder),
                "<fg=gray>{$runTime} ms</> <fg=green;options=bold>DONE</>"
            );

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Get a seeder instance from the container.
     */
    protected function getSeeder(): Seeder
    {
        $class = $this->input->getArgument('class') ?? $this->input->getOption('class');

        if (! str_contains($class, '\\')) {
            $class = 'Database\Seeders\\' . $class;
        }

        if ($class === 'Database\Seeders\DatabaseSeeder'
            && ! class_exists($class)) {
            $class = 'DatabaseSeeder';
        }

        return $this->hypervel->make($class)
            ->setContainer($this->hypervel)
            ->setCommand($this);
    }

    /**
     * Get the name of the database connection to use.
     *
     * Reads the current default via the resolver (which respects any active
     * Context override) rather than going straight to config, so seeding
     * honors scoped overrides applied by callers like Migrator::usingConnection().
     */
    protected function getDatabase(): string
    {
        $database = $this->input->getOption('database');

        return $database === null || $database === ''
            ? $this->resolver->getDefaultConnection()
            : $database;
    }
}
