<?php

declare(strict_types=1);

namespace Hypervel\Router;

use BackedEnum;
use Carbon\Carbon;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hyperf\Contract\SessionInterface;
use Hyperf\HttpMessage\Uri\Uri;
use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\HttpServer\Router\DispatcherFactory;
use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Router\UrlGenerator as UrlGeneratorContract;
use Hypervel\Contracts\Router\UrlRoutable;
use Hypervel\Support\Arr;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;

class UrlGenerator implements UrlGeneratorContract
{
    use InteractsWithTime;
    use Macroable;

    /**
     * The callback to use to format hosts.
     *
     * @var ?Closure
     */
    protected $formatHostUsing;

    /**
     * The callback to use to format paths.
     *
     * @var ?Closure
     */
    protected $formatPathUsing;

    protected ?string $signedKey = null;

    /**
     * A cached copy of the URL scheme for the current request.
     */
    protected ?string $cachedScheme = null;

    /**
     * The forced scheme for URLs.
     */
    protected ?string $forceScheme = null;

    public function __construct(protected Container $container)
    {
    }

    /**
     * Get the URL to a named route.
     *
     * @throws InvalidArgumentException
     */
    public function route(string $name, array $parameters = [], bool $absolute = true, string $server = 'http'): string
    {
        /** @var \Hypervel\Router\RouteCollector $router */
        $router = $this->container->make(DispatcherFactory::class)->getRouter($server);
        $namedRoutes = $router->getNamedRoutes();

        if (! array_key_exists($name, $namedRoutes)) {
            throw new InvalidArgumentException("Route [{$name}] not defined.");
        }

        $routeData = $namedRoutes[$name];

        $uri = array_reduce($routeData, function ($uri, $segment) use (&$parameters) {
            if (! is_array($segment)) {
                return $uri . $segment;
            }

            $value = $parameters[$segment[0]] ?? '';

            unset($parameters[$segment[0]]);

            return $uri . $value;
        }, '');

        $path = $this->format(
            $absolute ? $this->getRootUrl($this->formatScheme(null)) : '',
            $uri
        );

        if (! empty($parameters)) {
            $path .= '?' . http_build_query($parameters);
        }

        return $absolute ? $path : "/{$path}";
    }

    /**
     * Generate a url for the application.
     */
    public function to(string $path, array $extra = [], ?bool $secure = null): string
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }

        $extra = $this->formatParameters($extra);
        $tail = implode('/', array_map('rawurlencode', $extra));
        $root = $this->getRootUrl($this->formatScheme($secure));
        [$path, $query] = $this->extractQueryString($path);

        return $this->format(
            $root,
            '/' . trim($path . '/' . $tail, '/')
        ) . $query;
    }

    /**
     * Generate an absolute URL with the given query parameters.
     */
    public function query(string $path, array $query = [], array $extra = [], ?bool $secure = null): string
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
    public function secure(string $path, array $extra = []): string
    {
        return $this->to($path, $extra, true);
    }

    /**
     * Generate the URL to an application asset.
     */
    public function asset(string $path, ?bool $secure = null): string
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }

        $root = $this->getRootUrl($this->formatScheme($secure));

        return Str::finish($root, '/') . trim($path, '/');
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
        $root = $this->getRootUrl($this->formatScheme($secure));

        return $root . '/' . trim($path, '/');
    }

    /**
     * Get the default scheme for a raw URL.
     */
    public function formatScheme(?bool $secure = null): string
    {
        if (! is_null($secure)) {
            return $secure ? 'https://' : 'http://';
        }

        if (is_null($this->cachedScheme)) {
            $this->cachedScheme = $this->forceScheme ?: $this->getRequestUri()->getScheme() . '://';
        }

        return $this->cachedScheme;
    }

    /**
     * Create a signed route URL for a named route.
     *
     * @throws InvalidArgumentException
     */
    public function signedRoute(BackedEnum|string $name, array $parameters = [], DateInterval|DateTimeInterface|int|null $expiration = null, bool $absolute = true, string $server = 'http'): string
    {
        $this->ensureSignedRouteParametersAreNotReserved(
            $parameters = Arr::wrap($parameters)
        );

        if ($expiration) {
            $parameters = $parameters + ['expires' => $this->availableAt($expiration)];
        }

        ksort($parameters);

        return $this->route($name, $parameters + [
            'signature' => hash_hmac(
                'sha256',
                $this->route($name, $parameters, $absolute, $server),
                $this->getSignedKey()
            ),
        ], $absolute, $server);
    }

    /**
     * Ensure the given signed route parameters are not reserved.
     */
    protected function ensureSignedRouteParametersAreNotReserved(mixed $parameters): void
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
    public function temporarySignedRoute(BackedEnum|string $name, DateInterval|DateTimeInterface|int|null $expiration, array $parameters = [], bool $absolute = true, string $server = 'http'): string
    {
        return $this->signedRoute($name, $parameters, $expiration, $absolute, $server);
    }

    /**
     * Determine if the given request has a valid signature.
     */
    public function hasValidSignature(RequestInterface $request, bool $absolute = true, array $ignoreQuery = []): bool
    {
        return $this->hasCorrectSignature($request, $absolute, $ignoreQuery)
            && $this->signatureHasNotExpired($request);
    }

    /**
     * Determine if the given request has a valid signature for a relative URL.
     */
    public function hasValidRelativeSignature(RequestInterface $request, array $ignoreQuery = []): bool
    {
        return $this->hasValidSignature($request, false, $ignoreQuery);
    }

    /**
     * Determine if the signature from the given request matches the URL.
     */
    public function hasCorrectSignature(RequestInterface $request, bool $absolute = true, array $ignoreQuery = []): bool
    {
        $ignoreQuery[] = 'signature';

        /* @phpstan-ignore-next-line */
        $url = $absolute ? $request->url() : '/' . $request->path();

        $queryString = http_build_query(
            array_filter($request->query(), fn ($value, $key) => ! in_array($key, $ignoreQuery), ARRAY_FILTER_USE_BOTH)
        );

        $original = rtrim($url . '?' . $queryString, '?');

        if (hash_equals(
            hash_hmac('sha256', $original, $this->getSignedKey()),
            (string) $request->query('signature', '')
        )) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the expires timestamp from the given request is not from the past.
     */
    public function signatureHasNotExpired(RequestInterface $request): bool
    {
        $expires = $request->query('expires');

        return ! ($expires && Carbon::now()->getTimestamp() > $expires);
    }

    /**
     * Get the full URL for the current request.
     */
    public function full(): string
    {
        return (string) $this->getRequestUri();
    }

    /**
     * Get the current URL for the request.
     */
    public function current(): string
    {
        return rtrim(preg_replace('/\?.*/', '', $this->full()), '/');
    }

    /**
     * Get the URL for the previous request.
     */
    public function previous(bool|string $fallback = false): string
    {
        if (! RequestContext::has()) {
            return $this->getPreviousUrlFromSession()
                ?: ($fallback ? $this->to($fallback) : $this->to('/'));
        }

        $referrer = $this->container->make(RequestInterface::class)
            ->header('referer');
        $url = $referrer ? $this->to($referrer) : $this->getPreviousUrlFromSession();

        return $url ?: ($fallback ? $this->to($fallback) : $this->to('/'));
    }

    /**
     * Get the previous path info for the request.
     *
     * @param mixed $fallback
     */
    public function previousPath($fallback = false): string
    {
        $previousPath = str_replace($this->to('/'), '', rtrim(preg_replace('/\?.*/', '', $this->previous($fallback)), '/'));

        return $previousPath === '' ? '/' : $previousPath;
    }

    /**
     * Get the previous URL from the session if possible.
     */
    protected function getPreviousUrlFromSession(): ?string
    {
        if (! Context::has(SessionInterface::class)) {
            return null;
        }

        return $this->container->make(SessionInterface::class)
            ->previousUrl();
    }

    /**
     * Format the given URL segments into a single URL.
     */
    public function format(string $root, string $path): string
    {
        $path = '/' . trim($path, '/');

        if ($this->formatHostUsing) {
            $root = call_user_func($this->formatHostUsing, $root);
        }

        if ($this->formatPathUsing) {
            $path = call_user_func($this->formatPathUsing, $path);
        }

        return trim($root . $path, '/');
    }

    /**
     * Determine if the given path is a valid URL.
     */
    public function isValidUrl(string $path): bool
    {
        foreach (['#', '//', 'mailto:', 'tel:', 'sms:', 'http://', 'https://'] as $value) {
            if (str_starts_with($path, $value)) {
                return true;
            }
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Force the scheme for URLs.
     */
    public function forceScheme(?string $scheme): void
    {
        $this->cachedScheme = null;

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
     * Set the URL origin for all generated URLs.
     *
     * This is stored in coroutine Context for request isolation.
     */
    public function useOrigin(?string $root): void
    {
        if ($root !== null) {
            Context::set('__url.forced_root', rtrim($root, '/'));
        } else {
            Context::destroy('__url.forced_root');
        }

        // Clear the cached root so it will be recalculated
        Context::destroy('__request.root.uri');
    }

    /**
     * Set a callback to be used to format the host of generated URLs.
     */
    public function formatHostUsing(Closure $callback): static
    {
        $this->formatHostUsing = $callback;

        return $this;
    }

    /**
     * Set a callback to be used to format the path of generated URLs.
     */
    public function formatPathUsing(Closure $callback): static
    {
        $this->formatPathUsing = $callback;

        return $this;
    }

    /**
     * Set signed key for signing urls.
     */
    public function setSignedKey(?string $signedKey = null): static
    {
        $this->signedKey = $signedKey;

        return $this;
    }

    protected function formatParameters(array $parameters): array
    {
        foreach ($parameters as $key => $parameter) {
            if ($parameter instanceof UrlRoutable) {
                $parameters[$key] = $parameter->getRouteKey();
            }
        }

        return $parameters;
    }

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

    protected function getSignedKey(): string
    {
        if ($this->signedKey) {
            return $this->signedKey;
        }

        return $this->container->make('config')
            ->get('app.key');
    }

    protected function getRootUrl(string $scheme): string
    {
        // Check for forced root first
        $forcedRoot = Context::get('__url.forced_root');
        if ($forcedRoot !== null) {
            $root = new Uri($forcedRoot);

            return $root->withScheme(
                str_replace('://', '', $scheme)
            )->toString();
        }

        $root = Context::getOrSet('__request.root.uri', function () {
            $requestUri = $this->getRequestUri()->toString();
            $root = preg_replace(';^([^:]+://[^/?#]+).*$;', '\1', $requestUri);

            return new Uri($root);
        });

        return $root->withScheme(
            str_replace('://', '', $scheme)
        )->toString();
    }

    protected function getRequestUri(): Uri
    {
        if (RequestContext::has()) {
            return $this->container->make(RequestInterface::class)->getUri(); // @phpstan-ignore return.type (getUri() returns UriInterface but is always Uri in practice)
        }

        return new Uri($this->container->make('config')->get('app.url'));
    }
}
