<?php

declare(strict_types=1);

namespace Hypervel\Head\Inertia;

use Closure;
use Hypervel\Head\HeadManager;
use Hypervel\Head\Rendering\HeadRenderer;
use Hypervel\Inertia\Ssr\ConfiguresSsrRequests;
use Hypervel\Inertia\Ssr\DisablesSsr;
use Hypervel\Inertia\Ssr\ExcludesSsrPaths;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\HasHealthCheck;
use Hypervel\Inertia\Ssr\Response;
use Hypervel\Support\Arr;
use LogicException;

class UniqueHeadGateway implements ConfiguresSsrRequests, DisablesSsr, ExcludesSsrPaths, Gateway, HasHealthCheck
{
    /**
     * Create the SSR gateway decorator.
     */
    public function __construct(
        protected Gateway $gateway,
        protected HeadManager $head,
        protected HeadRenderer $renderer,
    ) {
    }

    /**
     * Dispatch the page to the SSR engine, stripping the page-managed head
     * elements from the response while preserving any other elements
     * produced by JavaScript SSR.
     *
     * @param array<string, mixed> $page
     */
    public function dispatch(array $page): ?Response
    {
        $response = $this->gateway->dispatch($page);

        if (is_null($response)) {
            return null;
        }

        /** @var array<int, string> $elements */
        $elements = Arr::get($page, 'props.' . $this->head->inertiaProp(), []);

        return new Response(
            $this->renderer->withoutInertiaElements($response->head, $elements),
            $response->body,
        );
    }

    /**
     * Forward conditional SSR disabling to the decorated gateway.
     */
    public function disable(Closure|bool $condition): void
    {
        if (! $this->gateway instanceof DisablesSsr) {
            throw new LogicException('The configured SSR gateway does not support disabling server-side rendering conditionally.');
        }

        $this->gateway->disable($condition);
    }

    /**
     * Forward SSR path exclusions to the decorated gateway.
     *
     * @param array<int, string>|string $paths
     */
    public function except(array|string $paths): void
    {
        if (! $this->gateway instanceof ExcludesSsrPaths) {
            throw new LogicException('The configured SSR gateway does not support excluding paths from server-side rendering.');
        }

        $this->gateway->except($paths);
    }

    /**
     * Forward SSR request configuration to the decorated gateway.
     */
    public function configureRequestUsing(?Closure $callback = null): void
    {
        if (! $this->gateway instanceof ConfiguresSsrRequests) {
            throw new LogicException('The configured SSR gateway does not support configuring server-side rendering requests.');
        }

        $this->gateway->configureRequestUsing($callback);
    }

    /**
     * Forward SSR health checks to the decorated gateway.
     */
    public function isHealthy(): bool
    {
        if (! $this->gateway instanceof HasHealthCheck) {
            throw new LogicException('The configured SSR gateway does not support health checks.');
        }

        return $this->gateway->isHealthy();
    }
}
