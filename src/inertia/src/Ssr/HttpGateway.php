<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Request;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\ResolvesCallables;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Vite;
use Hypervel\Support\Str;

class HttpGateway implements ConfiguresSsrRequests, DisablesSsr, ExcludesSsrPaths, Gateway, HasHealthCheck
{
    use ExcludesPaths;
    use ResolvesCallables;

    /**
     * The HTTP client connection for SSR requests.
     *
     * The service provider registers it with the configured timeouts. Its shared
     * transport handler reuses connections to the SSR server across requests.
     */
    public const string CONNECTION = 'inertia-ssr';

    /**
     * The time until which SSR is considered unavailable for this worker.
     *
     * Used to avoid flooding an unavailable SSR server with requests.
     * Cleared as soon as the render transport responds again.
     */
    private static ?float $ssrUnavailableUntil = null;

    /**
     * Get the per-request Inertia state.
     */
    private function state(): InertiaState
    {
        return InertiaState::current();
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

        if ($url === null) {
            return null;
        }

        $pendingRequest = $this->pendingRequest();

        try {
            $response = $pendingRequest->post($url, $page);
        } catch (RequestException $e) {
            // A configured retry() or throw() raises the failed response, which
            // still carries the SSR server's error details.
            $response = $e->response;
        } catch (ConnectionException $e) {
            $this->armTransportBackoff();
            $this->handleSsrFailure($page, [
                'error' => $e->getMessage(),
                'type' => 'connection',
            ]);

            return null;
        }

        self::$ssrUnavailableUntil = null;

        if ($response->failed()) {
            // Decode SSR bodies directly: Response::json() applies the HTTP client's
            // global decoding flags, which could make a malformed body throw
            // instead of falling back to client-side rendering.
            $decoded = json_decode($response->body(), true);
            $structured = is_array($decoded);

            if (! $structured) {
                $this->armTransportBackoff();
            }

            $this->handleSsrFailure($page, $structured ? $decoded : null);

            return null;
        }

        $data = json_decode($response->body(), true);

        if (! $this->isValidSsrResponse($data)) {
            $this->armTransportBackoff();
            $this->handleSsrFailure($page, ['error' => 'Invalid SSR response.']);

            return null;
        }

        return new Response(
            implode("\n", $data['head']),
            $data['body'],
        );
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
     * Configure the HTTP request that is sent to the SSR server.
     */
    public function configureRequestUsing(?Closure $callback = null): void
    {
        $this->state()->ssrRequestConfigurator = $callback;
    }

    /**
     * Create the pending HTTP request for the SSR server.
     */
    protected function pendingRequest(): PendingRequest
    {
        $request = Http::connection(self::CONNECTION);
        $configurator = $this->state()->ssrRequestConfigurator;

        if ($configurator === null) {
            return $request;
        }

        return $configurator($request) ?? $request;
    }

    /**
     * Handle an SSR rendering failure.
     *
     * @param array<string, mixed> $page
     * @param null|array<string, mixed> $error
     *
     * @throws SsrException
     */
    protected function handleSsrFailure(array $page, ?array $error): void
    {
        /** @var Dispatcher $events */
        $events = app('events');
        $hasListeners = $events->hasListeners(SsrRenderFailed::class);
        $throwOnError = config()->boolean('inertia.ssr.throw_on_error', false);

        if (! $hasListeners && ! $throwOnError) {
            return;
        }

        $event = new SsrRenderFailed(
            page: $page,
            error: $this->stringOrNull($error['error'] ?? null) ?? 'Unknown SSR error',
            type: SsrErrorType::fromString($this->stringOrNull($error['type'] ?? null)),
            hint: $this->stringOrNull($error['hint'] ?? null),
            browserApi: $this->stringOrNull($error['browserApi'] ?? null),
            stack: $this->stringOrNull($error['stack'] ?? null),
            sourceLocation: $this->stringOrNull($error['sourceLocation'] ?? null),
        );

        if ($hasListeners) {
            $events->dispatch($event);
        }

        if ($throwOnError) {
            throw SsrException::fromEvent($event);
        }
    }

    /**
     * Determine if the SSR feature is enabled.
     */
    protected function ssrIsEnabled(Request $request): bool
    {
        // Skip SSR while transport backoff is active.
        if (self::$ssrUnavailableUntil !== null && microtime(true) < self::$ssrUnavailableUntil) {
            return false;
        }

        $state = $this->state();

        $enabled = $state->ssrDisabled !== null
            ? ! $this->resolveCallable($state->ssrDisabled)
            : config()->boolean('inertia.ssr.enabled', true);

        return $enabled && ! $this->inExceptArray($request);
    }

    /**
     * Determine if the SSR server is healthy.
     */
    public function isHealthy(): bool
    {
        try {
            return $this->checkHealth();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Determine if the SSR server is healthy, throwing when it cannot be reached.
     *
     * @throws ConnectionException
     */
    public function checkHealth(): bool
    {
        $pendingRequest = $this->pendingRequest();

        try {
            return $pendingRequest->get($this->getProductionUrl('/health'))->successful();
        } catch (RequestException) {
            return false;
        }
    }

    /**
     * Shut down the SSR server.
     *
     * @throws ConnectionException
     */
    public function shutdown(): bool
    {
        $pendingRequest = $this->pendingRequest();

        try {
            return $pendingRequest->get($this->getProductionUrl('/shutdown'))->successful();
        } catch (RequestException) {
            return false;
        }
    }

    /**
     * Determine if the bundle existence should be ensured.
     */
    protected function shouldEnsureBundleExists(): bool
    {
        return config()->boolean('inertia.ssr.ensure_bundle_exists', true);
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
        $baseUrl = rtrim(config()->string('inertia.ssr.url', 'http://127.0.0.1:13714'), '/');

        return $baseUrl . $path;
    }

    /**
     * Get the Vite hot SSR URL.
     */
    protected function getHotUrl(string $path = '/'): ?string
    {
        $baseUrl = (string) config('inertia.ssr.hot_url');

        if ($baseUrl === '') {
            $baseUrl = @file_get_contents(Vite::hotFile());

            if ($baseUrl === false) {
                return null;
            }
        }

        return rtrim(trim($baseUrl), '/') . Str::start($path, '/');
    }

    /**
     * Determine if the decoded SSR response has the expected shape.
     */
    protected function isValidSsrResponse(mixed $data): bool
    {
        if (! is_array($data)
            || ! isset($data['head'], $data['body'])
            || ! is_array($data['head'])
            || ! is_string($data['body'])
        ) {
            return false;
        }

        foreach ($data['head'] as $head) {
            if (! is_string($head)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Activate SSR transport backoff.
     */
    private function armTransportBackoff(): void
    {
        self::$ssrUnavailableUntil = microtime(true)
            + config()->float('inertia.ssr.backoff', 5.0);
    }

    /**
     * Return the value when it is a string.
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$ssrUnavailableUntil = null;
    }
}
