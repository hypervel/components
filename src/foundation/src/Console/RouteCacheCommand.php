<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\RouteCollection;
use LogicException;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'route:cache')]
class RouteCacheCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'route:cache
                {--dump-to= : Internal option used to dump fresh compiled routes to a temporary file}';

    /**
     * The console command description.
     */
    protected string $description = 'Create a route cache file for faster route registration';

    /**
     * Create a new route cache command instance.
     */
    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Uses a subprocess to build the route cache. The parent clears the
     * existing cache file first, then spawns a child process that boots
     * fresh (loading routes from source files, not from stale cache),
     * compiles them, and writes the payload to a temp file. This avoids
     * overwriting process-global state (Container singleton, Facade caches)
     * that a second in-process Application bootstrap would corrupt.
     */
    public function handle(): int
    {
        // Subprocess branch: invoked internally via --dump-to, not by the user.
        // The app booted fresh (no cache file exists), so the router holds a
        // live RouteCollection loaded from source route definitions.
        if (is_string($dumpPath = $this->option('dump-to')) && $dumpPath !== '') {
            $routes = $this->hypervel['router']->getRoutes();

            if (! $routes instanceof RouteCollection) {
                throw new LogicException('Fresh route dump expected a live RouteCollection.');
            }

            $this->files->put($dumpPath, serialize($this->buildCachePayload($routes)));

            return self::SUCCESS;
        }

        $this->callSilent('route:clear');

        $compiled = $this->getFreshCompiledRoutesFromSubprocess();

        if (($compiled['attributes'] ?? []) === []) {
            $this->components->error("Your application doesn't have any routes.");

            return self::FAILURE;
        }

        $this->files->put(
            $this->hypervel->getCachedRoutesPath(),
            $this->buildRouteCacheFile($compiled)
        );

        $this->components->info('Routes cached successfully.');

        return self::SUCCESS;
    }

    /**
     * Build the cache payload by preparing routes for serialization in-place.
     *
     * This method only runs inside the isolated cache subprocess, so it is safe
     * to mutate the live route objects while compiling the payload.
     */
    protected function buildCachePayload(RouteCollection $routes): array
    {
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        return $routes->compile();
    }

    /**
     * Get a fresh compiled routes payload from an isolated child process.
     */
    protected function getFreshCompiledRoutesFromSubprocess(): array
    {
        $dumpPath = tempnam(sys_get_temp_dir(), 'hypervel-routes-');

        try {
            if ($dumpPath === false) {
                throw new LogicException('Unable to create a temporary file for the route cache dump.');
            }

            $process = new Process(
                [
                    PHP_BINARY,
                    $this->hypervel->basePath('artisan'),
                    'route:cache',
                    '--dump-to=' . $dumpPath,
                ],
                $this->hypervel->basePath(),
                [
                    'HYPERVEL_AUTOLOAD_PATH' => $this->resolveSubprocessAutoloadPath(),
                ],
            );

            $process->setTimeout(null);
            $process->mustRun();

            $compiled = unserialize($this->files->get($dumpPath));

            if (! is_array($compiled)) {
                throw new LogicException('The route cache subprocess returned an invalid payload.');
            }

            return $compiled;
        } finally {
            if (is_string($dumpPath)) {
                $this->files->delete($dumpPath);
            }
        }
    }

    /**
     * Build the route cache file.
     */
    protected function buildRouteCacheFile(array $compiled): string
    {
        $stub = $this->files->get(__DIR__ . '/stubs/routes.stub');

        return str_replace('{{routes}}', var_export($compiled, true), $stub);
    }

    /**
     * Resolve the Composer autoload path for the cache subprocess.
     */
    protected function resolveSubprocessAutoloadPath(): string
    {
        $componentRoot = dirname((new ReflectionClass(Filesystem::class))->getFileName(), 4);

        $candidates = array_unique([
            $this->hypervel->basePath('vendor/autoload.php'),
            $componentRoot . '/vendor/autoload.php',
            dirname($componentRoot, 2) . '/autoload.php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new LogicException('Unable to locate the Composer autoloader for the route cache subprocess.');
    }
}
