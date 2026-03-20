<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;

class ProviderRepository
{
    /**
     * Create a new service repository instance.
     */
    public function __construct(
        protected ApplicationContract $app,
        protected Filesystem $files,
        protected string $manifestPath
    ) {
    }

    /**
     * Register the application service providers.
     *
     * @param array<int, class-string> $providers
     */
    public function load(array $providers): void
    {
        $manifest = $this->loadManifest();

        if ($this->shouldRecompile($manifest, $providers)) {
            $manifest = $this->compileManifest($providers);
        }

        foreach ($manifest['eager'] as $provider) {
            $this->app->register($provider);
        }

        $this->app->addDeferredServices($manifest['deferred']);
    }

    /**
     * Load the service provider manifest file.
     *
     * @return null|array{providers: array, eager: array, deferred: array}
     */
    public function loadManifest(): ?array
    {
        if ($this->files->exists($this->manifestPath)) {
            $manifest = $this->files->getRequire($this->manifestPath);

            if ($manifest) {
                return $manifest;
            }
        }

        return null;
    }

    /**
     * Determine if the manifest should be compiled.
     *
     * @param null|array{providers: array, eager: array, deferred: array} $manifest
     * @param array<int, class-string> $providers
     */
    public function shouldRecompile(?array $manifest, array $providers): bool
    {
        return $manifest === null || $manifest['providers'] !== $providers;
    }

    /**
     * Compile the application service manifest file.
     *
     * @param array<int, class-string> $providers
     * @return array{providers: array, eager: array, deferred: array}
     */
    protected function compileManifest(array $providers): array
    {
        $manifest = $this->freshManifest($providers);

        foreach ($providers as $provider) {
            $instance = $this->createProvider($provider);

            if ($instance->isDeferred()) {
                foreach ($instance->provides() as $service) {
                    $manifest['deferred'][$service] = $provider;
                }
            } else {
                $manifest['eager'][] = $provider;
            }
        }

        return $this->writeManifest($manifest);
    }

    /**
     * Create a fresh service manifest data structure.
     *
     * @param array<int, class-string> $providers
     * @return array{providers: array, eager: array, deferred: array}
     */
    protected function freshManifest(array $providers): array
    {
        return ['providers' => $providers, 'eager' => [], 'deferred' => []];
    }

    /**
     * Write the service manifest file to disk.
     *
     * @param array{providers: array, eager: array, deferred: array} $manifest
     * @return array{providers: array, eager: array, deferred: array}
     *
     * @throws Exception
     */
    public function writeManifest(array $manifest): array
    {
        if (! is_writable($dirname = dirname($this->manifestPath))) {
            throw new Exception("The {$dirname} directory must be present and writable.");
        }

        $this->files->replace(
            $this->manifestPath,
            '<?php return ' . var_export($manifest, true) . ';'
        );

        return $manifest;
    }

    /**
     * Create a new provider instance.
     */
    public function createProvider(string $provider): ServiceProvider
    {
        return new $provider($this->app);
    }
}
