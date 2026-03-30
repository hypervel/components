<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use BackedEnum;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class UrlGenerator implements UrlGeneratorContract
{
    use InteractsWithTime;
    use Macroable;

    /**
     * Context key for the cached URL scheme.
     */
    protected const CACHED_SCHEME_CONTEXT_KEY = '__routing.url.cached_scheme';

    /**
     * Context key for the cached root URL.
     */
    protected const CACHED_ROOT_CONTEXT_KEY = '__routing.url.cached_root';

    /**
     * Context key for the forced root URL override.
     */
    protected const FORCED_ROOT_CONTEXT_KEY = '__routing.url.forced_root';

    /**
     * The route collection.
     */
    protected RouteCollectionInterface $routes;

    /**
     * The request instance.
     */
    protected Request $request;

    /**
     * The asset root URL.
     */
    protected ?string $assetRoot = null;

    /**
     * The forced URL root (process-global, set at boot).
     */
    protected ?string $forcedRoot = null;

    /**
     * The forced scheme for URLs.
     */
    protected ?string $forceScheme = null;

    /**
     * The root namespace being applied to controller actions.
     */
    protected ?string $rootNamespace = null;

    /**
     * The session resolver callable.
     *
     * @var null|callable
     */
    protected $sessionResolver;

    /**
     * The encryption key resolver callable.
     *
     * @var null|callable
     */
    protected $keyResolver;

    /**
     * The missing named route resolver callable.
     *
     * @var null|callable
     */
    protected $missingNamedRouteResolver;

    /**
     * The callback to use to format hosts.
     */
    protected ?Closure $formatHostUsing = null;

    /**
     * The callback to use to format paths.
     */
    protected ?Closure $formatPathUsing = null;

    /**
     * The route URL generator instance.
     */
    protected ?RouteUrlGenerator $routeGenerator = null;

    /**
     * Create a new URL generator instance.
     */
    public function __construct(RouteCollectionInterface $routes, Request $request, ?string $assetRoot = null)
    {
        $this->routes = $routes;
        $this->assetRoot = $assetRoot;

        $this->setRequest($request);
    }

    /**
     * Get the full URL for the current request.
     */
    public function full(): string
    {
        return $this->getRequest()->fullUrl();
    }

    /**
     * Get the current URL for the request.
     */
    public function current(): string
    {
        return $this->to($this->getRequest()->getPathInfo());
    }

    /**
     * Get the URL for the previous request.
     */
    public function previous(bool|string $fallback = false): string
    {
        $referrer = $this->getRequest()->headers->get('referer');

        $url = $referrer ? $this->to($referrer) : $this->getPreviousUrlFromSession();

        if ($url) {
            return $url;
        }
        if ($fallback) {
            return $this->to($fallback);
        }

        return $this->to('/');
    }

    /**
     * Get the previous path info for the request.
     */
    public function previousPath(bool|string $fallback = false): string
    {
        $previousPath = str_replace($this->to('/'), '', rtrim(preg_replace('/\?.*/', '', $this->previous($fallback)), '/'));

        return $previousPath === '' ? '/' : $previousPath;
    }

    /**
     * Get the previous URL from the session if possible.
     */
    protected function getPreviousUrlFromSession(): ?string
    {
        return $this->getSession()?->previousUrl();
    }

    /**
     * Generate an absolute URL to the given path.
     */
    public function to(string $path, array|string $extra = [], ?bool $secure = null): string
    {
        // First we will check if the URL is already a valid URL. If it is we will not
        // try to generate a new one but will simply return the URL as is, which is
        // convenient since developers do not always have to check if it's valid.
        if ($this->isValidUrl($path)) {
            return $path;
        }

        $tail = implode(
            '/',
            array_map(
                'rawurlencode',
                (array) $this->formatParameters($extra)
            )
        );

        // Once we have the scheme we will compile the "tail" by collapsing the values
        // into a single string delimited by slashes. This just makes it convenient
        // for passing the array of parameters to this URL as a list of segments.
        $root = $this->formatRoot($this->formatScheme($secure));

        [$path, $query] = $this->extractQueryString($path);

        return $this->format(
            $root,
            '/' . trim($path . '/' . $tail, '/')
        ) . $query;
    }

    /**
     * Generate an absolute URL with the given query parameters.
     */
    public function query(string $path, array $query = [], array|string $extra = [], ?bool $secure = null): string
    {
        [$path, $existingQueryString] = $this->extractQueryString($path);

        parse_str(Str::after($existingQueryString, '?'), $existingQueryArray);

        return rtrim($this->to($path . '?' . Arr::query(
            array_merge($existingQueryArray, $query)
        ), $extra, $secure), '?');
    }

    /**
     * Generate a secure, absolute URL to the given path.
     */
    public function secure(string $path, array $parameters = []): string
    {
        return $this->to($path, $parameters, true);
    }

    /**
     * Generate the URL to an application asset.
     */
    public function asset(string $path, ?bool $secure = null): string
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }

        // Once we get the root URL, we will check to see if it contains an index.php
        // file in the paths. If it does, we will remove it since it is not needed
        // for asset paths, but only for routes to endpoints in the application.
        $root = $this->assetRoot ?: $this->formatRoot($this->formatScheme($secure));

        return Str::finish($this->removeIndex($root), '/') . trim($path, '/');
    }

    /**
     * Generate the URL to a secure asset.
     */
    public function secureAsset(string $path): string
    {
        return $this->asset($path, true);
    }

    /**
     * Generate the URL to an asset from a custom root domain such as CDN, etc.
     */
    public function assetFrom(string $root, string $path, ?bool $secure = null): string
    {
        // Once we get the root URL, we will check to see if it contains an index.php
        // file in the paths. If it does, we will remove it since it is not needed
        // for asset paths, but only for routes to endpoints in the application.
        $root = $this->formatRoot($this->formatScheme($secure), $root);

        return $this->removeIndex($root) . '/' . trim($path, '/');
    }

    /**
     * Remove the index.php file from a path.
     */
    protected function removeIndex(string $root): string
    {
        $i = 'index.php';

        return str_contains($root, $i) ? str_replace('/' . $i, '', $root) : $root;
    }

    /**
     * Get the default scheme for a raw URL.
     */
    public function formatScheme(?bool $secure = null): string
    {
        if (! is_null($secure)) {
            return $secure ? 'https://' : 'http://';
        }

        return CoroutineContext::getOrSet(self::CACHED_SCHEME_CONTEXT_KEY, function () {
            return $this->forceScheme ?: $this->getRequest()->getScheme() . '://';
        });
    }

    /**
     * Create a signed route URL for a named route.
     *
     * @throws InvalidArgumentException
     */
    public function signedRoute(BackedEnum|string $name, mixed $parameters = [], DateInterval|DateTimeInterface|int|null $expiration = null, bool $absolute = true): string
    {
        $this->ensureSignedRouteParametersAreNotReserved(
            $parameters = Arr::wrap($parameters)
        );

        if ($expiration) {
            $parameters = $parameters + ['expires' => $this->availableAt($expiration)];
        }

        ksort($parameters);

        $key = call_user_func($this->keyResolver);

        return $this->route($name, $parameters + [
            'signature' => hash_hmac(
                'sha256',
                $this->route($name, $parameters, $absolute),
                is_array($key) ? $key[0] : $key
            ),
        ], $absolute);
    }

    /**
     * Ensure the given signed route parameters are not reserved.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureSignedRouteParametersAreNotReserved(array $parameters): void
    {
        if (array_key_exists('signature', $parameters)) {
            throw new InvalidArgumentException(
                '"Signature" is a reserved parameter when generating signed routes. Please rename your route parameter.'
            );
        }

        if (array_key_exists('expires', $parameters)) {
            throw new InvalidArgumentException(
                '"Expires" is a reserved parameter when generating signed routes. Please rename your route parameter.'
            );
        }
    }

    /**
     * Create a temporary signed route URL for a named route.
     */
    public function temporarySignedRoute(BackedEnum|string $name, DateInterval|DateTimeInterface|int $expiration, array $parameters = [], bool $absolute = true): string
    {
        return $this->signedRoute($name, $parameters, $expiration, $absolute);
    }

    /**
     * Determine if the given request has a valid signature.
     */
    public function hasValidSignature(Request $request, bool $absolute = true, Closure|array $ignoreQuery = []): bool
    {
        return $this->hasCorrectSignature($request, $absolute, $ignoreQuery)
            && $this->signatureHasNotExpired($request);
    }

    /**
     * Determine if the given request has a valid signature for a relative URL.
     */
    public function hasValidRelativeSignature(Request $request, Closure|array $ignoreQuery = []): bool
    {
        return $this->hasValidSignature($request, false, $ignoreQuery);
    }

    /**
     * Determine if the signature from the given request matches the URL.
     */
    public function hasCorrectSignature(Request $request, bool $absolute = true, Closure|array $ignoreQuery = []): bool
    {
        $url = $absolute ? $request->url() : '/' . $request->path();

        $queryString = (new Collection(explode('&', (string) $request->server->get('QUERY_STRING'))))
            ->reject(function ($parameter) use ($ignoreQuery) {
                $parameter = Str::before($parameter, '=');

                if ($parameter === 'signature') {
                    return true;
                }

                if ($ignoreQuery instanceof Closure) {
                    return $ignoreQuery($parameter);
                }

                return in_array($parameter, $ignoreQuery, true);
            })
            ->join('&');

        $original = rtrim($url . '?' . $queryString, '?');

        $keys = call_user_func($this->keyResolver);

        $keys = is_array($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            if (hash_equals(
                hash_hmac('sha256', $original, $key),
                (string) $request->query('signature', '')
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the expires timestamp from the given request is not from the past.
     */
    public function signatureHasNotExpired(Request $request): bool
    {
        $expires = $request->query('expires');

        return ! ($expires && Carbon::now()->getTimestamp() > $expires);
    }

    /**
     * Get the URL to a named route.
     *
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException
     * @throws InvalidArgumentException
     */
    public function route(BackedEnum|string $name, mixed $parameters = [], bool $absolute = true): string
    {
        if ($name instanceof BackedEnum && ! is_string($name = $name->value)) {
            throw new InvalidArgumentException('Attribute [name] expects a string backed enum.');
        }

        if (! is_null($route = $this->routes->getByName($name))) {
            return $this->toRoute($route, $parameters, $absolute);
        }

        if (! is_null($this->missingNamedRouteResolver)
            && ! is_null($url = call_user_func($this->missingNamedRouteResolver, $name, $parameters, $absolute))) {
            return $url;
        }

        throw new RouteNotFoundException("Route [{$name}] not defined.");
    }

    /**
     * Get the URL for a given route instance.
     *
     * @throws \Hypervel\Routing\Exceptions\UrlGenerationException
     */
    public function toRoute(Route $route, mixed $parameters, bool $absolute): string
    {
        return $this->routeUrl()->to(
            $route,
            $parameters,
            $absolute
        );
    }

    /**
     * Get the URL to a controller action.
     *
     * @throws InvalidArgumentException
     */
    public function action(array|string $action, array|string $parameters = [], bool $absolute = true): string
    {
        if (is_null($route = $this->routes->getByAction($action = $this->formatAction($action)))) {
            throw new InvalidArgumentException("Action {$action} not defined.");
        }

        return $this->toRoute($route, $parameters, $absolute);
    }

    /**
     * Format the given controller action.
     */
    protected function formatAction(array|string $action): string
    {
        if (is_array($action)) {
            $action = '\\' . implode('@', $action);
        }

        if ($this->rootNamespace && ! str_starts_with($action, '\\')) {
            return $this->rootNamespace . '\\' . $action;
        }

        return trim($action, '\\');
    }

    /**
     * Format the array of URL parameters.
     */
    public function formatParameters(mixed $parameters): array
    {
        $parameters = Arr::wrap($parameters);

        foreach ($parameters as $key => $parameter) {
            if ($parameter instanceof UrlRoutable) {
                $parameters[$key] = $parameter->getRouteKey();
            }
        }

        return $parameters;
    }

    /**
     * Extract the query string from the given path.
     */
    protected function extractQueryString(string $path): array
    {
        if (($queryPosition = strpos($path, '?')) !== false) {
            return [
                substr($path, 0, $queryPosition),
                substr($path, $queryPosition),
            ];
        }

        return [$path, ''];
    }

    /**
     * Get the base URL for the request.
     */
    public function formatRoot(string $scheme, ?string $root = null): string
    {
        if (is_null($root)) {
            $root = CoroutineContext::getOrSet(self::CACHED_ROOT_CONTEXT_KEY, function () {
                return CoroutineContext::get(self::FORCED_ROOT_CONTEXT_KEY)
                    ?? $this->forcedRoot
                    ?: $this->getRequest()->root();
            });
        }

        $start = str_starts_with($root, 'http://') ? 'http://' : 'https://';

        return preg_replace('~' . $start . '~', $scheme, $root, 1);
    }

    /**
     * Format the given URL segments into a single URL.
     */
    public function format(string $root, string $path, ?Route $route = null): string
    {
        $path = '/' . trim($path, '/');

        if ($this->formatHostUsing) {
            $root = call_user_func($this->formatHostUsing, $root, $route);
        }

        if ($this->formatPathUsing) {
            $path = call_user_func($this->formatPathUsing, $path, $route);
        }

        return trim($root . $path, '/');
    }

    /**
     * Determine if the given path is a valid URL.
     */
    public function isValidUrl(string $path): bool
    {
        if (! preg_match('~^(#|//|https?://|(mailto|tel|sms):)~', $path)) {
            return filter_var($path, FILTER_VALIDATE_URL) !== false;
        }

        return true;
    }

    /**
     * Get the route URL generator instance.
     *
     * The RouteUrlGenerator is cached on the instance for its default parameters,
     * but its request is always resolved fresh from the UrlGenerator to ensure
     * coroutine safety in Swoole's long-lived workers.
     */
    protected function routeUrl(): RouteUrlGenerator
    {
        if (! $this->routeGenerator) {
            $this->routeGenerator = new RouteUrlGenerator($this);
        }

        return $this->routeGenerator;
    }

    /**
     * Set the default named parameters used by the URL generator.
     */
    public function defaults(array $defaults): void
    {
        $this->routeUrl()->defaults($defaults);
    }

    /**
     * Get the default named parameters used by the URL generator.
     */
    public function getDefaultParameters(): array
    {
        return $this->routeUrl()->defaultParameters;
    }

    /**
     * Force the scheme for URLs.
     */
    public function forceScheme(?string $scheme): void
    {
        CoroutineContext::forget(self::CACHED_SCHEME_CONTEXT_KEY);

        $this->forceScheme = $scheme ? $scheme . '://' : null;
    }

    /**
     * Force the use of the HTTPS scheme for all generated URLs.
     */
    public function forceHttps(bool $force = true): void
    {
        if ($force) {
            $this->forceScheme('https');
        }
    }

    /**
     * Set the URL origin for the current request.
     *
     * Stored in coroutine Context for request isolation — one request's
     * origin override does not affect concurrent requests.
     */
    public function useOrigin(?string $root): void
    {
        if ($root !== null) {
            CoroutineContext::set(self::FORCED_ROOT_CONTEXT_KEY, rtrim($root, '/'));
        } else {
            CoroutineContext::forget(self::FORCED_ROOT_CONTEXT_KEY);
        }

        CoroutineContext::forget(self::CACHED_ROOT_CONTEXT_KEY);
    }

    /**
     * Set the URL origin for all generated asset URLs.
     */
    public function useAssetOrigin(?string $root): void
    {
        $this->assetRoot = $root ? rtrim($root, '/') : null;
    }

    /**
     * Flush all per-request Context state.
     *
     * Clears forced root, cached root, and cached scheme from coroutine
     * Context. Used in test teardown to prevent state leaking between tests
     * when not running in coroutines.
     */
    public static function flushRequestState(): void
    {
        CoroutineContext::forget(self::FORCED_ROOT_CONTEXT_KEY);
        CoroutineContext::forget(self::CACHED_ROOT_CONTEXT_KEY);
        CoroutineContext::forget(self::CACHED_SCHEME_CONTEXT_KEY);
    }

    /**
     * Set a callback to be used to format the host of generated URLs.
     *
     * @return $this
     */
    public function formatHostUsing(Closure $callback): static
    {
        $this->formatHostUsing = $callback;

        return $this;
    }

    /**
     * Set a callback to be used to format the path of generated URLs.
     *
     * @return $this
     */
    public function formatPathUsing(Closure $callback): static
    {
        $this->formatPathUsing = $callback;

        return $this;
    }

    /**
     * Get the path formatter being used by the URL generator.
     */
    public function pathFormatter(): Closure
    {
        return $this->formatPathUsing ?: function ($path) {
            return $path;
        };
    }

    /**
     * Get the request instance.
     *
     * In Swoole's long-lived workers, the UrlGenerator is a singleton.
     * The request must be resolved from the coroutine-local RequestContext
     * to avoid one coroutine's request leaking to another.
     */
    public function getRequest(): Request
    {
        return RequestContext::getOrNull() ?? $this->request;
    }

    /**
     * Set the current request instance.
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;

        CoroutineContext::forget(self::CACHED_ROOT_CONTEXT_KEY);
        CoroutineContext::forget(self::CACHED_SCHEME_CONTEXT_KEY);

        tap($this->routeGenerator?->defaultParameters ?: [], function ($defaults) {
            $this->routeGenerator = null;

            if (! empty($defaults)) {
                $this->defaults($defaults);
            }
        });
    }

    /**
     * Set the route collection.
     *
     * @return $this
     */
    public function setRoutes(RouteCollectionInterface $routes): static
    {
        $this->routes = $routes;

        return $this;
    }

    /**
     * Get the session implementation from the resolver.
     */
    protected function getSession(): mixed
    {
        if ($this->sessionResolver) {
            return call_user_func($this->sessionResolver);
        }

        return null;
    }

    /**
     * Set the session resolver for the generator.
     *
     * @return $this
     */
    public function setSessionResolver(callable $sessionResolver): static
    {
        $this->sessionResolver = $sessionResolver;

        return $this;
    }

    /**
     * Set the encryption key resolver.
     *
     * @return $this
     */
    public function setKeyResolver(callable $keyResolver): static
    {
        $this->keyResolver = $keyResolver;

        return $this;
    }

    /**
     * Clone a new instance of the URL generator with a different encryption key resolver.
     */
    public function withKeyResolver(callable $keyResolver): static
    {
        return (clone $this)->setKeyResolver($keyResolver);
    }

    /**
     * Set the callback that should be used to attempt to resolve missing named routes.
     *
     * @return $this
     */
    public function resolveMissingNamedRoutesUsing(callable $missingNamedRouteResolver): static
    {
        $this->missingNamedRouteResolver = $missingNamedRouteResolver;

        return $this;
    }

    /**
     * Get the root controller namespace.
     */
    public function getRootControllerNamespace(): ?string
    {
        return $this->rootNamespace;
    }

    /**
     * Set the root controller namespace.
     *
     * @return $this
     */
    public function setRootControllerNamespace(string $rootNamespace): static
    {
        $this->rootNamespace = $rootNamespace;

        return $this;
    }
}
