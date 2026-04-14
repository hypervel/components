<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\Response as SsrResponse;

/**
 * Per-request Inertia state stored in coroutine Context.
 *
 * All request-scoped Inertia state lives here instead of on singleton
 * service classes. This ensures complete isolation between concurrent
 * requests in Swoole's long-running worker model.
 */
class InertiaState
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
     * @var array<string, mixed>
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
     * Set the page data and dispatch SSR if not already dispatched.
     *
     * Used by Blade directives and view components to trigger SSR
     * rendering. The result is cached so multiple calls (e.g. both
     * @inertia and @inertiaHead) only dispatch once.
     *
     * @param array<string, mixed> $page
     */
    public static function dispatchSsr(array $page): ?SsrResponse
    {
        $state = CoroutineContext::getOrSet(self::CONTEXT_KEY, fn () => new self);
        $state->page = $page;

        if (! $state->ssrDispatched) {
            $state->ssrDispatched = true;
            $state->ssrResponse = app(Gateway::class)->dispatch($state->page);
        }

        return $state->ssrResponse;
    }
}
