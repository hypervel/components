<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use ErrorException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Http\MaintenanceModeBypassCookie;
use Hypervel\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PreventRequestsDuringMaintenance
{
    use ExcludesPaths;

    /**
     * The URIs that should be excluded.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    /**
     * The URIs that should be accessible during maintenance.
     *
     * @var array<int, string>
     */
    protected static array $neverPrevent = [];

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Application $app
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @throws HttpException
     * @throws ErrorException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->inExceptArray($request)) {
            return $next($request);
        }

        if ($this->app->maintenanceMode()->active()) {
            try {
                $data = $this->app->maintenanceMode()->data();
            } catch (ErrorException $exception) {
                if (! $this->app->maintenanceMode()->active()) { // @phpstan-ignore booleanNot.alwaysFalse (race condition guard for drivers without worker-lifetime caching)
                    return $next($request);
                }

                throw $exception;
            }

            if (isset($data['secret']) && $request->path() === $data['secret']) {
                return $this->bypassResponse($data['secret']);
            }

            if ($this->hasValidBypassCookie($request, $data)) {
                return $next($request);
            }

            if (isset($data['redirect'])) {
                $path = $data['redirect'] === '/'
                    ? $data['redirect']
                    : trim($data['redirect'], '/');

                if ($request->path() !== $path) {
                    return redirect($path);
                }
            }

            if (isset($data['template'])) {
                return response(
                    $data['template'],
                    $data['status'] ?? 503,
                    $this->getHeaders($data)
                );
            }

            throw new HttpException(
                $data['status'] ?? 503,
                'Service Unavailable',
                null,
                $this->getHeaders($data)
            );
        }

        return $next($request);
    }

    /**
     * Determine if the incoming request has a maintenance mode bypass cookie.
     */
    protected function hasValidBypassCookie(Request $request, array $data): bool
    {
        return isset($data['secret'])
                && $request->cookie('hypervel_maintenance')
                && MaintenanceModeBypassCookie::isValid(
                    $request->cookie('hypervel_maintenance'),
                    $data['secret']
                );
    }

    /**
     * Redirect the user to their intended destination with a maintenance mode bypass cookie.
     */
    protected function bypassResponse(string $secret): mixed
    {
        return redirect()->intended('/')->withCookie(
            MaintenanceModeBypassCookie::create($secret)
        );
    }

    /**
     * Get the headers that should be sent with the response.
     */
    protected function getHeaders(array $data): array
    {
        $headers = isset($data['retry']) ? ['Retry-After' => $data['retry']] : [];

        if (isset($data['refresh'])) {
            $headers['Refresh'] = $data['refresh'];
        }

        return $headers;
    }

    /**
     * Get the URIs that should be excluded.
     */
    public function getExcludedPaths(): array
    {
        return array_merge($this->except, static::$neverPrevent);
    }

    /**
     * Indicate that the given URIs should always be accessible.
     */
    public static function except(array|string $uris): void
    {
        static::$neverPrevent = array_values(array_unique(
            array_merge(static::$neverPrevent, Arr::wrap($uris))
        ));
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$neverPrevent = [];
    }
}
