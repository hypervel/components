<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\RouteCollection;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use Throwable;

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
     * Uses a subprocess to build the route cache from source without
     * disturbing either the existing cache or process-global state in the
     * parent process.
     */
    public function handle(): int
    {
        // Subprocess branch: invoked internally via --dump-to, not by the user.
        // The app booted against a guaranteed-unused cache path, so the router
        // holds a live RouteCollection loaded from source route definitions.
        if (is_string($dumpPath = $this->option('dump-to')) && $dumpPath !== '') {
            $routes = $this->hypervel['router']->getRoutes();

            if (! $routes instanceof RouteCollection) {
                throw new LogicException('Fresh route dump expected a live RouteCollection.');
            }

            $this->files->replace($dumpPath, serialize($this->buildCachePayload($routes)));

            return self::SUCCESS;
        }

        $compiled = $this->getFreshCompiledRoutesFromSubprocess();

        if (($compiled['attributes'] ?? []) === []) {
            $this->components->error("Your application doesn't have any routes.");

            return self::FAILURE;
        }

        $cachePath = $this->hypervel->getCachedRoutesPath();
        $contents = $this->buildRouteCacheFile($compiled);
        $mode = null;

        if ($this->files->exists($cachePath)) {
            $permissions = $this->files->chmod($cachePath);

            if (! is_string($permissions)) {
                throw new RuntimeException("Unable to determine permissions for [{$cachePath}].");
            }

            $mode = octdec($permissions);
        }

        $this->files->replace($cachePath, $contents, $mode);

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
        $dumpPath = @tempnam(sys_get_temp_dir(), 'hypervel-routes-');
        $buildPath = null;
        $exception = null;

        try {
            if ($dumpPath === false) {
                throw new LogicException('Unable to create a temporary file for the route cache dump.');
            }

            $buildPath = $dumpPath . '.cache';

            if ($this->files->exists($buildPath)) {
                throw new LogicException("The alternate cache path [{$buildPath}] already exists.");
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
                    'APP_ROUTES_CACHE' => $buildPath,
                    'HYPERVEL_AUTOLOAD_PATH' => $this->resolveSubprocessAutoloadPath(),
                ],
            );

            $process->setTimeout(null);
            $process->mustRun();

            $serialized = $this->files->get($dumpPath);

            if ($serialized === '') {
                throw new LogicException('The route cache subprocess returned an empty payload.');
            }

            $compiled = @unserialize($serialized);

            if (! is_array($compiled)) {
                throw new LogicException('The route cache subprocess returned an invalid payload.');
            }

            return $compiled;
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            $cleanupException = null;

            foreach ([$dumpPath, $buildPath] as $path) {
                if (! is_string($path)) {
                    continue;
                }

                try {
                    if (! $this->files->delete($path) && $this->files->exists($path)) {
                        throw new RuntimeException("Unable to delete the temporary route cache file [{$path}].");
                    }
                } catch (Throwable $throwable) {
                    $cleanupException ??= $throwable;
                }
            }

            if ($exception === null && $cleanupException !== null) {
                throw $cleanupException;
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
