<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Whoops;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Arr;
use Whoops\Handler\PrettyPageHandler;

class WhoopsHandler
{
    /**
     * Create a new Whoops handler for debug mode.
     */
    public function forDebug(): PrettyPageHandler
    {
        return tap(new PrettyPageHandler(), function ($handler) {
            $handler->handleUnconditionally(true);

            $this->registerApplicationPaths($handler)
                ->registerBlacklist($handler)
                ->registerEditor($handler);
        });
    }

    /**
     * Register the application paths with the handler.
     */
    protected function registerApplicationPaths(PrettyPageHandler $handler): static
    {
        $handler->setApplicationPaths(
            array_flip($this->directoriesExceptVendor())
        );

        return $this;
    }

    /**
     * Get the application paths except for the "vendor" directory.
     */
    protected function directoriesExceptVendor(): array
    {
        return Arr::except(
            array_flip((new Filesystem())->directories(base_path())),
            [base_path('vendor')]
        );
    }

    /**
     * Register the blacklist with the handler.
     */
    protected function registerBlacklist(PrettyPageHandler $handler): static
    {
        foreach (config('app.debug_blacklist', config('app.debug_hide', [])) as $key => $secrets) {
            foreach ($secrets as $secret) {
                $handler->blacklist($key, $secret);
            }
        }

        return $this;
    }

    /**
     * Register the editor with the handler.
     */
    protected function registerEditor(PrettyPageHandler $handler): static
    {
        if (config('app.editor', false)) {
            $handler->setEditor(config('app.editor'));
        }

        return $this;
    }
}
