<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Listeners;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Events\ServeCommandStarted;
use Hypervel\Workbench\AuthServiceProvider;
use RuntimeException;

class PublishAssets
{
    /**
     * Create a new listener instance.
     */
    public function __construct(
        protected Application $app,
        protected Filesystem $files,
    ) {
    }

    /**
     * Publish the authentication page assets when serving the runtime skeleton.
     *
     * Only the disposable runtime copy owned by this process is written to. Other
     * applications keep their existing assets and use the vendor:publish command.
     */
    public function handle(ServeCommandStarted $event): void
    {
        foreach (ServiceProvider::pathsToPublish(AuthServiceProvider::class, 'hypervel-assets') as $from => $to) {
            if ($this->files->exists($to)) {
                continue;
            }

            if (! Bootstrapper::ownsRuntimePath($this->app->basePath()) || ! $this->isInsideBasePath($to)) {
                $event->components->warn(sprintf(
                    'Workbench assets are missing from [%s]. Publish them with: vendor/bin/testbench vendor:publish --provider="%s" --tag=hypervel-assets',
                    $to,
                    AuthServiceProvider::class,
                ));

                continue;
            }

            if (! $this->files->copyDirectory($from, $to)) {
                throw new RuntimeException("Unable to publish Workbench assets to [{$to}].");
            }
        }
    }

    /**
     * Determine if the given path resolves inside the application's base path.
     *
     * The nearest existing ancestor is resolved, so a public path or directory
     * that links outside the runtime copy is never written through.
     */
    protected function isInsideBasePath(string $path): bool
    {
        $ancestor = $path;

        while (! file_exists($ancestor)) {
            $ancestor = dirname($ancestor);
        }

        $basePath = realpath($this->app->basePath());
        $ancestor = realpath($ancestor);

        return $basePath !== false
            && $ancestor !== false
            && str_starts_with($ancestor . DIRECTORY_SEPARATOR, $basePath . DIRECTORY_SEPARATOR);
    }
}
