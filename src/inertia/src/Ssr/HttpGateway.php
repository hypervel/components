<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Hypervel\Http\Request;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\ResolvesCallables;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Vite;
use Hypervel\Support\Str;

class HttpGateway implements DisablesSsr, ExcludesSsrPaths, Gateway, HasHealthCheck
{
    use ExcludesPaths;
    use ResolvesCallables;

    /**
     * The time until which SSR is considered unavailable for this worker.
     *
     * Used as a circuit breaker to avoid flooding a dead SSR server
     * with requests. Reset after the backoff period expires.
     */
    private static ?float $ssrUnavailableUntil = null;

    /**
     * The reusable Guzzle client for SSR requests.
     *
     * Cached for the worker lifetime to avoid rebuilding the client,
     * handler stack, and curl handle cache on every SSR dispatch.
     */
    private static ?ClientInterface $ssrClient = null;

    /**
     * A testing-only client override.
     */
    private static ?ClientInterface $testingClient = null;

    /**
     * Get the per-request Inertia state.
     */
    private function state(): InertiaState
    {
        return CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);
    }

    /**
     * Get the Guzzle client for SSR requests.
     *
     * Uses a dedicated raw Guzzle client instead of the Http facade to avoid
     * per-request PendingRequest, HandlerStack, and Client allocations.
     * The client is cached for the worker lifetime, allowing Guzzle's
     * internal CurlFactory to reuse curl handles (TCP connection reuse).
     */
    protected function ssrClient(): ClientInterface
    {
        if (self::$testingClient !== null) {
            return self::$testingClient;
        }

        return self::$ssrClient ??= new Client([
            'connect_timeout' => (int) config('inertia.ssr.connect_timeout', 2),
            'timeout' => (int) config('inertia.ssr.timeout', 5),
            'cookies' => false,
            'http_errors' => false,
        ]);
    }

    /**
     * Set a Guzzle client for testing purposes.
     */
    public static function useTestingClient(?ClientInterface $client): void
    {
        self::$testingClient = $client;
    }

    /**
     * Dispatch the Inertia page to the SSR engine via HTTP.
     *
     * @param array<string, mixed> $page
     */
    public function dispatch(array $page, ?Request $request = null): ?Response
    {
        if (! $this->ssrIsEnabled($request ?? request())) {
            return null;
        }

        $isHot = Vite::isRunningHot();

        if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists()) {
            return null;
        }

        $url = $isHot
            ? $this->getHotUrl('/__inertia_ssr')
            : $this->getProductionUrl('/render');

        try {
            $response = $this->ssrClient()->request('POST', $url, [
                'json' => $page,
            ]);

            if ($response->getStatusCode() >= 400) {
                $decoded = json_decode((string) $response->getBody(), true);

                $this->handleSsrFailure($page, is_array($decoded) ? $decoded : null);

                return null;
            }

            $data = json_decode((string) $response->getBody(), true);

            if (! $data) {
                return null;
            }

            // SSR succeeded — clear any previous backoff
            self::$ssrUnavailableUntil = null;

            return new Response(
                implode("\n", $data['head'] ?? []),
                $data['body'] ?? ''
            );
        } catch (SsrException $e) {
            throw $e;
        } catch (TransferException $e) {
            $this->handleSsrFailure($page, [
                'error' => $e->getMessage(),
                'type' => 'connection',
            ]);

            return null;
        }
    }

    /**
     * Set the condition that determines if SSR should be disabled.
     */
    public function disable(Closure|bool $condition): void
    {
        $this->state()->ssrDisabled = $condition;
    }

    /**
     * Exclude the given paths from server-side rendering.
     *
     * @param array<int, string>|string $paths
     */
    public function except(array|string $paths): void
    {
        $state = $this->state();
        $state->ssrExcludedPaths = array_merge($state->ssrExcludedPaths, Arr::wrap($paths));
    }

    /**
     * Get the paths excluded from SSR for the current request.
     *
     * Overrides ExcludesPaths::getExcludedPaths() to read from
     * per-request InertiaState instead of an instance property.
     *
     * @return array<int, string>
     */
    public function getExcludedPaths(): array
    {
        return $this->state()->ssrExcludedPaths;
    }

    /**
     * Handle an SSR rendering failure.
     *
     * Sets the circuit breaker backoff and dispatches a failure event.
     *
     * @param array<string, mixed> $page
     * @param null|array<string, mixed> $error
     *
     * @throws SsrException
     */
    protected function handleSsrFailure(array $page, ?array $error): void
    {
        // Activate circuit breaker to avoid pile-up on a dead SSR server
        self::$ssrUnavailableUntil = microtime(true) + (float) config('inertia.ssr.backoff', 5.0);

        $event = new SsrRenderFailed(
            page: $page,
            error: $error['error'] ?? 'Unknown SSR error',
            type: SsrErrorType::fromString($error['type'] ?? null),
            hint: $error['hint'] ?? null,
            browserApi: $error['browserApi'] ?? null,
            stack: $error['stack'] ?? null,
            sourceLocation: $error['sourceLocation'] ?? null,
        );

        // Dispatch the already-built event directly (avoids double construction)
        event($event);

        // Throw an exception if configured (useful for E2E testing)
        if (config('inertia.ssr.throw_on_error', false)) {
            throw SsrException::fromEvent($event);
        }
    }

    /**
     * Determine if the SSR feature is enabled.
     */
    protected function ssrIsEnabled(Request $request): bool
    {
        // Circuit breaker: skip SSR if recently failed
        if (self::$ssrUnavailableUntil !== null && microtime(true) < self::$ssrUnavailableUntil) {
            return false;
        }

        $state = $this->state();

        $enabled = $state->ssrDisabled !== null
            ? ! $this->resolveCallable($state->ssrDisabled)
            : config('inertia.ssr.enabled', true);

        return $enabled && ! $this->inExceptArray($request);
    }

    /**
     * Determine if the SSR server is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $response = $this->ssrClient()->request('GET', $this->getProductionUrl('/health'));

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (TransferException) {
            return false;
        }
    }

    /**
     * Determine if the bundle existence should be ensured.
     */
    protected function shouldEnsureBundleExists(): bool
    {
        return (bool) config('inertia.ssr.ensure_bundle_exists', true);
    }

    /**
     * Check if an SSR bundle exists.
     */
    protected function bundleExists(): bool
    {
        return app(BundleDetector::class)->detect() !== null;
    }

    /**
     * Get the production SSR server URL.
     */
    public function getProductionUrl(string $path = '/'): string
    {
        $path = Str::start($path, '/');
        $baseUrl = rtrim((string) config('inertia.ssr.url', 'http://127.0.0.1:13714'), '/');

        return $baseUrl . $path;
    }

    /**
     * Get the Vite hot SSR URL.
     */
    protected function getHotUrl(string $path = '/'): string
    {
        return rtrim(file_get_contents(Vite::hotFile())) . $path;
    }

    /**
     * Reset static state for testing.
     */
    public static function flushState(): void
    {
        self::$ssrUnavailableUntil = null;
        self::$ssrClient = null;
        self::$testingClient = null;
    }
}
