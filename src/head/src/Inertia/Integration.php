<?php

declare(strict_types=1);

namespace Hypervel\Head\Inertia;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Head\HeadManager;
use Hypervel\Head\Rendering\HeadRenderer;
use Hypervel\Inertia\InertiaServiceProvider;
use Hypervel\Inertia\Ssr\Gateway;

class Integration
{
    /**
     * Create the Inertia integration registrar.
     */
    public function __construct(protected Application $app)
    {
    }

    /**
     * Register Hypervel Head's optional Inertia integration.
     *
     * Boot-only. Gateway bindings and middleware affect every request in the worker.
     */
    public function register(): void
    {
        if ($this->app->getProviders(InertiaServiceProvider::class) === []) {
            return;
        }

        $this->decorateSsrGateway();
        $this->registerMiddleware();
    }

    /**
     * Share head elements during every request in coroutine-local Inertia state.
     */
    protected function registerMiddleware(): void
    {
        $prepend = static function (Kernel $kernel): void {
            // Register the lazy prop before global middleware can render an error.
            $kernel->prependMiddleware(ShareHead::class);
        };

        $this->app->afterResolving(Kernel::class, $prepend);

        if ($this->app->resolved(Kernel::class)) {
            $prepend($this->app->make(Kernel::class));
        }
    }

    /**
     * Strip page-managed head elements from Inertia SSR responses at the
     * gateway, so @head remains authoritative for the initial document no
     * matter where it renders relative to Inertia's head component.
     */
    protected function decorateSsrGateway(): void
    {
        $this->app->extend(Gateway::class, fn (Gateway $gateway): Gateway => new UniqueHeadGateway(
            $gateway,
            $this->app->make(HeadManager::class),
            $this->app->make(HeadRenderer::class),
        ));
    }
}
