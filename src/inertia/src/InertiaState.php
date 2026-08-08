<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\ReplicableContext;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\Response as SsrResponse;

/**
 * Inertia configuration and request state stored in coroutine Context.
 *
 * Providers configure one boot baseline outside a coroutine. Each request
 * receives an independent copy so request mutations remain isolated in
 * Swoole's long-running worker model.
 */
class InertiaState implements ReplicableContext
{
    /**
     * The coroutine Context key for this state.
     */
    public const CONTEXT_KEY = '__inertia.state';

    /**
     * The root Blade template name.
     */
    public string $rootView = 'app';

    /**
     * The shared properties included in every Inertia response.
     *
     * @var array<array-key, mixed|ProvidesInertiaProperties>
     */
    public array $sharedProps = [];

    /**
     * The asset version resolver or value.
     */
    public Closure|string|null $version = null;

    /**
     * Whether browser history encryption is enabled for this request.
     */
    public ?bool $encryptHistory = null;

    /**
     * The URL resolver callback for this request.
     */
    public ?Closure $urlResolver = null;

    /**
     * The component name transformer callback.
     */
    public ?Closure $componentTransformer = null;

    // SSR per-request state (replaces upstream SsrState)

    /**
     * The page data for the current request's SSR dispatch.
     *
     * @var array<string, mixed>
     */
    public array $page = [];

    /**
     * The cached SSR response for the current request.
     */
    public ?SsrResponse $ssrResponse = null;

    /**
     * Whether the SSR gateway has been dispatched for this request.
     */
    public bool $ssrDispatched = false;

    // SSR per-request flags (moved from HttpGateway)

    /**
     * The condition that determines if SSR is disabled for this request.
     */
    public Closure|bool|null $ssrDisabled = null;

    /**
     * The paths excluded from SSR for this request.
     *
     * @var array<int, string>
     */
    public array $ssrExcludedPaths = [];

    /**
     * Get the current Inertia state.
     */
    public static function current(): self
    {
        if (CoroutineContext::has(self::CONTEXT_KEY)) {
            /** @var self $state */
            $state = CoroutineContext::get(self::CONTEXT_KEY);

            return $state;
        }

        // Providers configure Inertia before the server starts request coroutines,
        // so each request begins with an independent copy of that boot baseline.
        /** @var null|self $baseline */
        $baseline = CoroutineContext::getFromNonCoroutine(self::CONTEXT_KEY);

        $state = $baseline?->replicate() ?? new self;
        CoroutineContext::set(self::CONTEXT_KEY, $state);

        return $state;
    }

    /**
     * Dispatch SSR if it has not already been dispatched for this request.
     */
    public function dispatchSsr(): ?SsrResponse
    {
        if (! $this->ssrDispatched) {
            $this->ssrDispatched = true;
            $this->ssrResponse = app(Gateway::class)->dispatch($this->page);
        }

        return $this->ssrResponse;
    }

    /**
     * Create an independent copy with the same state.
     */
    public function replicate(): static
    {
        return clone $this;
    }
}
