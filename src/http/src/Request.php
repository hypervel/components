<?php

declare(strict_types=1);

namespace Hypervel\Http;

use ArrayAccess;
use Closure;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Session\SymfonySessionDecorator;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Uri;
use Override;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @method array validate(array $rules, ...$params)
 * @method array validateWithBag(string $errorBag, array $rules, ...$params)
 * @method bool hasValidSignature(bool $absolute = true)
 * @method bool hasValidRelativeSignature()
 * @method bool hasValidSignatureWhileIgnoring($ignoreQuery = [], $absolute = true)
 * @method bool hasValidRelativeSignatureWhileIgnoring($ignoreQuery = [])
 * @method bool inertia() Registered as a macro by Hypervel\Inertia\InertiaServiceProvider.
 */
class Request extends SymfonyRequest implements Arrayable, ArrayAccess
{
    use Concerns\CanBePrecognitive;
    use Concerns\InteractsWithContentTypes;
    use Concerns\InteractsWithFlashData;
    use Concerns\InteractsWithInput;
    use Conditionable;
    use Macroable;

    /**
     * Forwarded-header parameter mapping.
     */
    protected const FORWARDED_PARAMS = [
        self::HEADER_X_FORWARDED_FOR => 'for',
        self::HEADER_X_FORWARDED_HOST => 'host',
        self::HEADER_X_FORWARDED_PROTO => 'proto',
        self::HEADER_X_FORWARDED_PORT => 'host',
    ];

    /**
     * Mapping of trusted-header bitmask flags to header names.
     */
    protected const TRUSTED_HEADERS = [
        self::HEADER_FORWARDED => 'FORWARDED',
        self::HEADER_X_FORWARDED_FOR => 'X_FORWARDED_FOR',
        self::HEADER_X_FORWARDED_HOST => 'X_FORWARDED_HOST',
        self::HEADER_X_FORWARDED_PROTO => 'X_FORWARDED_PROTO',
        self::HEADER_X_FORWARDED_PORT => 'X_FORWARDED_PORT',
        self::HEADER_X_FORWARDED_PREFIX => 'X_FORWARDED_PREFIX',
    ];

    /**
     * The decoded JSON content for the request.
     */
    protected ?InputBag $json = null;

    /**
     * All of the converted files for the request.
     *
     * @var null|array<int, UploadedFile|UploadedFile[]>
     */
    protected ?array $convertedFiles = null;

    /**
     * The user resolver callback.
     */
    protected ?Closure $userResolver = null;

    /**
     * The route resolver callback.
     */
    protected ?Closure $routeResolver = null;

    /**
     * The cached "Accept" header value.
     */
    protected ?string $cachedAcceptHeader = null;

    /**
     * Trusted proxy IP addresses / CIDR ranges for the current request.
     *
     * Stored per-instance instead of on Symfony's process-global statics so
     * concurrent Swoole coroutines don't share trusted request configuration.
     *
     * @var string[]
     */
    protected array $trustedProxiesValue = [];

    /**
     * Bitmask of trusted forwarded-headers for the current request.
     */
    protected int $trustedHeaderSetValue = -1;

    /**
     * Compiled trusted-host regex patterns for the current request.
     *
     * @var string[]
     */
    protected array $trustedHostPatternsValue = [];

    /**
     * Memoized cache of host strings that matched a trusted pattern.
     *
     * @var string[]
     */
    protected array $trustedHostsValue = [];

    /**
     * Memoized cache of parsed trusted-header values for this request.
     *
     * @var array<string, array>
     */
    protected array $trustedValuesCacheValue = [];

    /**
     * One-shot flag preventing duplicate "Suspicious Host" exceptions per request.
     */
    protected bool $isHostValidValue = true;

    /**
     * One-shot flag preventing duplicate "ConflictingHeaders" exceptions per request.
     */
    protected bool $isForwardedValidValue = true;

    /**
     * Initialize the request data.
     */
    #[Override]
    public function initialize(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null): void
    {
        parent::initialize($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->trustedProxiesValue = [];
        $this->trustedHeaderSetValue = -1;
        $this->trustedHostPatternsValue = [];
        $this->trustedHostsValue = [];
        $this->resetTrustedRequestCaches();
    }

    /**
     * Create a new HTTP request from PHP superglobals.
     *
     * @throws RuntimeException always — superglobals don't exist in Swoole workers
     */
    public static function createFromGlobals(): static
    {
        throw new RuntimeException('Request::createFromGlobals() is not supported in Hypervel. Requests are created from Swoole request objects.');
    }

    /**
     * Set the trusted proxies on the current request.
     */
    public static function setTrustedProxies(array $proxies, int $trustedHeaderSet): void
    {
        // Keep Symfony's static API, but write to the current coroutine request
        // so concurrent requests never share proxy trust configuration.
        $request = RequestContext::getOrNull();

        if (! $request instanceof self) {
            return;
        }

        if (false !== $i = array_search('REMOTE_ADDR', $proxies, true)) {
            if (null !== $remote = $request->server->get('REMOTE_ADDR')) {
                $proxies[$i] = $remote;
            } else {
                unset($proxies[$i]);
                $proxies = array_values($proxies);
            }
        }

        if (false !== ($i = array_search('PRIVATE_SUBNETS', $proxies, true))
            || false !== ($i = array_search('private_ranges', $proxies, true))) {
            unset($proxies[$i]);
            $proxies = array_merge($proxies, IpUtils::PRIVATE_SUBNETS);
        }

        $request->trustedProxiesValue = $proxies;
        $request->trustedHeaderSetValue = $trustedHeaderSet;
        $request->resetTrustedRequestCaches();
    }

    /**
     * Get the trusted proxies for the current request.
     *
     * @return string[]
     */
    public static function getTrustedProxies(): array
    {
        $request = RequestContext::getOrNull();

        return $request instanceof self ? $request->trustedProxiesValue : [];
    }

    /**
     * Get the trusted-header bitmask for the current request.
     */
    public static function getTrustedHeaderSet(): int
    {
        $request = RequestContext::getOrNull();

        return $request instanceof self ? $request->trustedHeaderSetValue : -1;
    }

    /**
     * Set the trusted host patterns for the current request.
     */
    public static function setTrustedHosts(array $hostPatterns): void
    {
        $request = RequestContext::getOrNull();

        if (! $request instanceof self) {
            return;
        }

        $request->trustedHostPatternsValue = array_map(
            fn ($hostPattern) => sprintf('{%s}i', $hostPattern),
            $hostPatterns,
        );
        $request->trustedHostsValue = [];
        $request->resetTrustedRequestCaches();
    }

    /**
     * Get the trusted host patterns for the current request.
     *
     * @return string[]
     */
    public static function getTrustedHosts(): array
    {
        $request = RequestContext::getOrNull();

        return $request instanceof self ? $request->trustedHostPatternsValue : [];
    }

    /**
     * Return the Request instance.
     *
     * @return $this
     */
    public function instance(): static
    {
        return $this;
    }

    /**
     * Get the request method.
     */
    public function method(): string
    {
        return $this->getMethod();
    }

    /**
     * Get a URI instance for the request.
     */
    public function uri(): Uri
    {
        return Uri::of($this->fullUrl());
    }

    /**
     * Get the root URL for the application.
     */
    public function root(): string
    {
        return rtrim($this->getSchemeAndHttpHost() . $this->getBaseUrl(), '/');
    }

    /**
     * Get the URL (no query string) for the request.
     */
    public function url(): string
    {
        return rtrim(preg_replace('/\?.*/', '', $this->getUri()), '/');
    }

    /**
     * Get the full URL for the request.
     */
    public function fullUrl(): string
    {
        $query = $this->getQueryString();

        $question = $this->getBaseUrl() . $this->getPathInfo() === '/' ? '/?' : '?';

        return $query ? $this->url() . $question . $query : $this->url();
    }

    /**
     * Get the full URL for the request with the added query string parameters.
     */
    public function fullUrlWithQuery(array $query): string
    {
        $question = $this->getBaseUrl() . $this->getPathInfo() === '/' ? '/?' : '?';

        return count($this->query()) > 0
            ? $this->url() . $question . Arr::query(array_merge($this->query(), $query))
            : $this->fullUrl() . $question . Arr::query($query);
    }

    /**
     * Get the full URL for the request without the given query string parameters.
     */
    public function fullUrlWithoutQuery(array|string $keys): string
    {
        $query = Arr::except($this->query(), $keys);

        $question = $this->getBaseUrl() . $this->getPathInfo() === '/' ? '/?' : '?';

        return count($query) > 0
            ? $this->url() . $question . Arr::query($query)
            : $this->url();
    }

    /**
     * Get the current path info for the request.
     */
    public function path(): string
    {
        $pattern = trim($this->getPathInfo(), '/');

        return $pattern === '' ? '/' : $pattern;
    }

    /**
     * Get the current decoded path info for the request.
     */
    public function decodedPath(): string
    {
        return rawurldecode($this->path());
    }

    /**
     * Get a segment from the URI (1 based index).
     */
    public function segment(int $index, ?string $default = null): ?string
    {
        return Arr::get($this->segments(), $index - 1, $default);
    }

    /**
     * Get all of the segments for the request path.
     */
    public function segments(): array
    {
        $segments = explode('/', $this->decodedPath());

        return array_values(array_filter($segments, function ($value) {
            return $value !== '';
        }));
    }

    /**
     * Determine if the current request URI matches a pattern.
     */
    public function is(mixed ...$patterns): bool
    {
        return (new Collection($patterns))
            ->contains(fn ($pattern) => Str::is($pattern, $this->decodedPath()));
    }

    /**
     * Determine if the route name matches a given pattern.
     */
    public function routeIs(mixed ...$patterns): bool
    {
        return $this->route() && $this->route()->named(...$patterns);
    }

    /**
     * Determine if the current request URL and query string match a pattern.
     */
    public function fullUrlIs(mixed ...$patterns): bool
    {
        return (new Collection($patterns))
            ->contains(fn ($pattern) => Str::is($pattern, $this->fullUrl()));
    }

    /**
     * Get the host name.
     */
    public function host(): string
    {
        return $this->getHost();
    }

    /**
     * Get the HTTP host being requested.
     */
    public function httpHost(): string
    {
        return $this->getHttpHost();
    }

    /**
     * Get the scheme and HTTP host.
     */
    public function schemeAndHttpHost(): string
    {
        return $this->getSchemeAndHttpHost();
    }

    /**
     * Determine if the request is the result of an AJAX call.
     */
    public function ajax(): bool
    {
        return $this->isXmlHttpRequest();
    }

    /**
     * Determine if the request is the result of a PJAX call.
     */
    public function pjax(): bool
    {
        return $this->headers->get('X-PJAX') == true;
    }

    /**
     * Determine if the request is the result of a prefetch call.
     */
    public function prefetch(): bool
    {
        return strcasecmp($this->server->get('HTTP_X_MOZ') ?? '', 'prefetch') === 0
            || strcasecmp($this->headers->get('Purpose') ?? '', 'prefetch') === 0
            || strcasecmp($this->headers->get('Sec-Purpose') ?? '', 'prefetch') === 0;
    }

    /**
     * Determine if the request is over HTTPS.
     */
    public function secure(): bool
    {
        return $this->isSecure();
    }

    /**
     * Get the client IP address.
     */
    public function ip(): ?string
    {
        return $this->getClientIp();
    }

    /**
     * Get the client IP addresses.
     */
    public function ips(): array
    {
        return $this->getClientIps();
    }

    /**
     * Get the client IP addresses.
     */
    #[Override]
    public function getClientIps(): array
    {
        $ip = $this->server->get('REMOTE_ADDR');

        if (! $this->isFromTrustedProxy()) {
            return [$ip];
        }

        return $this->getTrustedValues(self::HEADER_X_FORWARDED_FOR, $ip) ?: [$ip];
    }

    /**
     * Return the root URL from which this request is executed.
     */
    #[Override]
    public function getBaseUrl(): string
    {
        $trustedPrefix = '';

        if ($this->isFromTrustedProxy()
            && $trustedPrefixValues = $this->getTrustedValues(self::HEADER_X_FORWARDED_PREFIX)) {
            $trustedPrefix = rtrim($trustedPrefixValues[0], '/');
        }

        return $trustedPrefix . $this->getBaseUrlReal();
    }

    /**
     * Return the port on which the request is made.
     */
    #[Override]
    public function getPort(): int|string|null
    {
        if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_PORT)) {
            $host = $host[0];
        } elseif ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {
            $host = $host[0];
        } elseif (! $host = $this->headers->get('HOST')) {
            return $this->server->get('SERVER_PORT');
        }

        if ($host[0] === '[') {
            $pos = strpos($host, ':', strrpos($host, ']'));
        } else {
            $pos = strrpos($host, ':');
        }

        if ($pos !== false && $port = substr($host, $pos + 1)) {
            return (int) $port;
        }

        return $this->getScheme() === 'https' ? 443 : 80;
    }

    /**
     * Determine whether the request is secure.
     */
    #[Override]
    public function isSecure(): bool
    {
        if ($this->isFromTrustedProxy()
            && $proto = $this->getTrustedValues(self::HEADER_X_FORWARDED_PROTO)) {
            return in_array(strtolower($proto[0]), ['https', 'on', 'ssl', '1'], true);
        }

        $https = $this->server->get('HTTPS');

        return $https && (! is_string($https) || strtolower($https) !== 'off');
    }

    /**
     * Return the host name.
     */
    #[Override]
    public function getHost(): string
    {
        if ($this->isFromTrustedProxy() && $host = $this->getTrustedValues(self::HEADER_X_FORWARDED_HOST)) {
            $host = $host[0];
        } else {
            $host = $this->headers->get('HOST') ?: $this->server->get('SERVER_NAME') ?: $this->server->get('SERVER_ADDR', '');
        }

        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)));

        if ($host && ! static::isHostValid($host)) {
            if (! $this->isHostValidValue) {
                return '';
            }
            $this->isHostValidValue = false;

            throw new SuspiciousOperationException(sprintf('Invalid Host "%s".', $host));
        }

        if (count($this->trustedHostPatternsValue) > 0) {
            if (in_array($host, $this->trustedHostsValue, true)) {
                return $host;
            }

            foreach ($this->trustedHostPatternsValue as $pattern) {
                if (preg_match($pattern, $host)) {
                    $this->trustedHostsValue[] = $host;

                    return $host;
                }
            }

            if (! $this->isHostValidValue) {
                return '';
            }
            $this->isHostValidValue = false;

            throw new SuspiciousOperationException(sprintf('Untrusted Host "%s".', $host));
        }

        return $host;
    }

    /**
     * Determine whether this request originated from a trusted proxy.
     */
    #[Override]
    public function isFromTrustedProxy(): bool
    {
        return $this->trustedProxiesValue
            && IpUtils::checkIp($this->server->get('REMOTE_ADDR', ''), $this->trustedProxiesValue);
    }

    /**
     * Get the client user agent.
     */
    public function userAgent(): ?string
    {
        return $this->headers->get('User-Agent');
    }

    #[Override]
    public function getAcceptableContentTypes(): array
    {
        $currentAcceptHeader = $this->headers->get('Accept');

        if ($this->cachedAcceptHeader !== $currentAcceptHeader) {
            // Flush acceptable content types so Symfony re-calculates them...
            $this->acceptableContentTypes = null;
            $this->cachedAcceptHeader = $currentAcceptHeader;
        }

        return parent::getAcceptableContentTypes();
    }

    /**
     * Merge new input into the current request's input array.
     *
     * @return $this
     */
    public function merge(array $input): static
    {
        return tap($this, function (Request $request) use ($input) {
            $request->getInputSource()
                ->replace((new Collection($input))->reduce(
                    fn ($requestInput, $value, $key) => data_set($requestInput, $key, $value),
                    $this->getInputSource()->all()
                ));
        });
    }

    /**
     * Merge new input into the request's input, but only when that key is missing from the request.
     *
     * @return $this
     */
    public function mergeIfMissing(array $input): static
    {
        return $this->merge(
            (new Collection($input))
                ->filter(fn ($value, $key) => $this->missing($key))
                ->toArray()
        );
    }

    /**
     * Replace the input values for the current request.
     *
     * @return $this
     */
    public function replace(array $input): static
    {
        $this->getInputSource()->replace($input);

        return $this;
    }

    /**
     * Retrieve a parameter from the request.
     *
     * Instead, you may use the "input" method.
     *
     * @deprecated use ->input() instead
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this !== $result = $this->attributes->get($key, $this)) {
            return $result;
        }

        if ($this->query->has($key)) {
            return $this->query->all()[$key];
        }

        if ($this->request->has($key)) {
            return $this->request->all()[$key];
        }

        return $default;
    }

    /**
     * Get the JSON payload for the request.
     *
     * @return ($key is null ? InputBag : mixed)
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (! isset($this->json)) {
            $this->json = new InputBag((array) json_decode($this->getContent() ?: '[]', true));
        }

        if (is_null($key)) {
            return $this->json;
        }

        return data_get($this->json->all(), $key, $default);
    }

    /**
     * Get the input source for the request.
     */
    protected function getInputSource(): InputBag
    {
        if ($this->isJson()) {
            return $this->json();
        }

        return in_array($this->getRealMethod(), ['GET', 'HEAD']) ? $this->query : $this->request;
    }

    /**
     * Create a new request instance from the given request.
     */
    public static function createFrom(self $from, ?self $to = null): static
    {
        $request = $to ?: new static;

        $files = array_filter($from->files->all());

        $request->initialize(
            $from->query->all(),
            $from->request->all(),
            $from->attributes->all(),
            $from->cookies->all(),
            $files,
            $from->server->all(),
            $from->getContent()
        );

        $request->headers->replace($from->headers->all());

        $request->setRequestLocale($from->getLocale());

        $request->setDefaultRequestLocale($from->getDefaultLocale());

        $request->setJson($from->json());

        if ($from->hasSession()) {
            $request->setHypervelSession($from->session());
        }

        $request->setUserResolver($from->getUserResolver());

        $request->setRouteResolver($from->getRouteResolver());

        $request->copyTrustedStateFrom($from);

        /** @var static $request */
        return $request;
    }

    /**
     * Create a Hypervel request from a Symfony instance.
     */
    public static function createFromBase(SymfonyRequest $request): static
    {
        $newRequest = new static(
            $request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), (new static)->filterFiles($request->files->all()) ?? [], $request->server->all()
        );

        $newRequest->headers->replace($request->headers->all());

        $newRequest->content = $request->content;

        if ($newRequest->isJson()) {
            $newRequest->request = $newRequest->json();
        }

        if ($request instanceof self) {
            $newRequest->copyTrustedStateFrom($request);
        }

        return $newRequest;
    }

    #[Override]
    public function duplicate(?array $query = null, ?array $request = null, ?array $attributes = null, ?array $cookies = null, ?array $files = null, ?array $server = null): static
    {
        return parent::duplicate($query, $request, $attributes, $cookies, $this->filterFiles($files), $server);
    }

    /**
     * Clone the current request.
     */
    #[Override]
    public function __clone()
    {
        parent::__clone();

        // Symfony's duplicate() clones the request internally, so this covers
        // both direct clone calls and duplicate() without duplicating reset code.
        $this->resetTrustedRequestCaches();
    }

    /**
     * Copy trusted request configuration from another request.
     */
    protected function copyTrustedStateFrom(self $from): void
    {
        $this->trustedProxiesValue = $from->trustedProxiesValue;
        $this->trustedHeaderSetValue = $from->trustedHeaderSetValue;
        $this->trustedHostPatternsValue = $from->trustedHostPatternsValue;
        $this->trustedHostsValue = $from->trustedHostsValue;

        // Copy configuration only. Parsed forwarded values and one-shot
        // exception flags belong to this distinct request object's lifecycle.
        $this->resetTrustedRequestCaches();
    }

    /**
     * Reset the trusted-values cache and one-shot exception flags.
     */
    protected function resetTrustedRequestCaches(): void
    {
        $this->trustedValuesCacheValue = [];
        $this->isHostValidValue = true;
        $this->isForwardedValidValue = true;
    }

    /**
     * Return the real base URL without the trusted reverse proxy prefix.
     */
    protected function getBaseUrlReal(): string
    {
        // Symfony keeps this helper private, but getBaseUrl() needs the same
        // unprefixed value before adding any trusted X-Forwarded-Prefix.
        return $this->baseUrl ??= $this->prepareBaseUrl();
    }

    /**
     * Parse the trusted forwarded-header values for the requested type.
     */
    protected function getTrustedValues(int $type, ?string $ip = null): array
    {
        // Header values are part of the key; trusted-proxy/header config changes
        // clear this cache in the setters because they affect filtering too.
        $cacheKey = $type . "\0"
            . (($this->trustedHeaderSetValue & $type) ? $this->headers->get(self::TRUSTED_HEADERS[$type]) : '');
        $cacheKey .= "\0" . $ip . "\0" . $this->headers->get(self::TRUSTED_HEADERS[self::HEADER_FORWARDED]);

        if (isset($this->trustedValuesCacheValue[$cacheKey])) {
            return $this->trustedValuesCacheValue[$cacheKey];
        }

        $clientValues = [];
        $forwardedValues = [];

        if (($this->trustedHeaderSetValue & $type)
            && $this->headers->has(self::TRUSTED_HEADERS[$type])) {
            foreach (explode(',', $this->headers->get(self::TRUSTED_HEADERS[$type])) as $value) {
                $clientValues[] = ($type === self::HEADER_X_FORWARDED_PORT ? '0.0.0.0:' : '') . trim($value);
            }
        }

        if (($this->trustedHeaderSetValue & self::HEADER_FORWARDED)
            && isset(self::FORWARDED_PARAMS[$type])
            && $this->headers->has(self::TRUSTED_HEADERS[self::HEADER_FORWARDED])) {
            $forwarded = $this->headers->get(self::TRUSTED_HEADERS[self::HEADER_FORWARDED]);
            $parts = HeaderUtils::split($forwarded, ',;=');
            $param = self::FORWARDED_PARAMS[$type];

            foreach ($parts as $subParts) {
                if (null === $value = HeaderUtils::combine($subParts)[$param] ?? null) {
                    continue;
                }

                if ($type === self::HEADER_X_FORWARDED_PORT) {
                    if (str_ends_with($value, ']') || false === $value = strrchr($value, ':')) {
                        $value = $this->isSecure() ? ':443' : ':80';
                    }
                    $value = '0.0.0.0' . $value;
                }

                $forwardedValues[] = $value;
            }
        }

        if ($ip !== null) {
            $clientValues = $this->normalizeAndFilterClientIps($clientValues, $ip);
            $forwardedValues = $this->normalizeAndFilterClientIps($forwardedValues, $ip);
        }

        if ($forwardedValues === $clientValues || ! $clientValues) {
            return $this->trustedValuesCacheValue[$cacheKey] = $forwardedValues;
        }

        if (! $forwardedValues) {
            return $this->trustedValuesCacheValue[$cacheKey] = $clientValues;
        }

        if (! $this->isForwardedValidValue) {
            return $this->trustedValuesCacheValue[$cacheKey] = $ip !== null
                ? ['0.0.0.0', $ip]
                : [];
        }
        $this->isForwardedValidValue = false;

        throw new ConflictingHeadersException(sprintf(
            'The request has both a trusted "%s" header and a trusted "%s" header, conflicting with each other.'
            . ' You should either configure your proxy to remove one of them,'
            . ' or configure your project to distrust the offending one.',
            self::TRUSTED_HEADERS[self::HEADER_FORWARDED],
            self::TRUSTED_HEADERS[$type],
        ));
    }

    /**
     * Normalize and filter trusted client IPs.
     */
    protected function normalizeAndFilterClientIps(array $clientIps, string $ip): array
    {
        if (! $clientIps) {
            return [];
        }

        $clientIps[] = $ip;
        $firstTrustedIp = null;

        foreach ($clientIps as $key => $clientIp) {
            if (strpos($clientIp, '.')) {
                $index = strpos($clientIp, ':');
                if ($index) {
                    $clientIps[$key] = $clientIp = substr($clientIp, 0, $index);
                }
            } elseif (str_starts_with($clientIp, '[')) {
                $index = strpos($clientIp, ']', 1);
                $clientIps[$key] = $clientIp = substr($clientIp, 1, $index - 1);
            }

            if (! filter_var($clientIp, FILTER_VALIDATE_IP)) {
                unset($clientIps[$key]);

                continue;
            }

            if (IpUtils::checkIp($clientIp, $this->trustedProxiesValue)) {
                unset($clientIps[$key]);
                $firstTrustedIp ??= $clientIp;
            }
        }

        return $clientIps ? array_reverse($clientIps) : [$firstTrustedIp];
    }

    /**
     * Validate a host string per Symfony's URL-spec rules.
     */
    protected static function isHostValid(string $host): bool
    {
        if ($host[0] === '[') {
            return $host[-1] === ']'
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        }

        if (preg_match('/\.[0-9]++\.?$/D', $host)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_NULL_ON_FAILURE) !== null;
        }

        return preg_replace('/[-a-zA-Z0-9_]++\.?/', '', $host) === '';
    }

    /**
     * Filter the given array of files, removing any empty values.
     */
    protected function filterFiles(mixed $files): mixed
    {
        if (! $files) {
            return null;
        }

        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $files[$key] = $this->filterFiles($files[$key]);
            }

            if (empty($files[$key])) {
                unset($files[$key]);
            }
        }

        return $files;
    }

    /**
     * @phpstan-assert-if-true SymfonySessionDecorator $this->session
     */
    #[Override]
    public function hasSession(bool $skipIfUninitialized = false): bool
    {
        return $this->session instanceof SymfonySessionDecorator;
    }

    #[Override]
    public function getSession(): SessionInterface
    {
        return $this->hasSession()
            ? $this->session
            : throw new SessionNotFoundException;
    }

    /**
     * Get the session associated with the request.
     *
     * @return \Hypervel\Contracts\Session\Session
     *
     * @throws RuntimeException
     */
    public function session()
    {
        if (! $this->hasSession()) {
            throw new RuntimeException('Session store not set on request.');
        }

        return $this->session->store;
    }

    /**
     * Set the session instance on the request.
     *
     * @param \Hypervel\Contracts\Session\Session $session
     */
    public function setHypervelSession($session): void
    {
        $this->session = new SymfonySessionDecorator($session);
    }

    /**
     * Set the locale for the request instance.
     */
    public function setRequestLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Set the default locale for the request instance.
     */
    public function setDefaultRequestLocale(string $locale): void
    {
        $this->defaultLocale = $locale;
    }

    /**
     * Get the user making the request.
     */
    public function user(?string $guard = null): mixed
    {
        return call_user_func($this->getUserResolver(), $guard);
    }

    /**
     * Get the route handling the request.
     *
     * @return ($param is null ? null|\Hypervel\Routing\Route : null|object|string)
     */
    public function route(?string $param = null, mixed $default = null): mixed
    {
        $route = call_user_func($this->getRouteResolver());

        if (is_null($route) || is_null($param)) {
            return $route;
        }

        if ($route->hasParameters()) {
            return $route->parameter($param, $default);
        }

        // Fall back to snapshotted parameters — used when the request is
        // inspected after its coroutine has ended (e.g. in test assertions
        // after MakesHttpRequests::call() returns from its waiter coroutine)
        return Arr::get($this->attributes->get('_route_params', []), $param, $default);
    }

    /**
     * Get a unique fingerprint for the request / route / IP address.
     *
     * @throws RuntimeException
     */
    public function fingerprint(): string
    {
        if (! $route = $this->route()) {
            throw new RuntimeException('Unable to generate fingerprint. Route unavailable.');
        }

        return sha1(implode('|', array_merge(
            $route->methods(),
            [$route->getDomain(), $route->uri(), $this->ip()]
        )));
    }

    /**
     * Set the JSON payload for the request.
     *
     * @return $this
     */
    public function setJson(InputBag $json): static
    {
        $this->json = $json;

        return $this;
    }

    /**
     * Get the user resolver callback.
     */
    public function getUserResolver(): Closure
    {
        return $this->userResolver ?: function () {
        };
    }

    /**
     * Set the user resolver callback.
     *
     * @return $this
     */
    public function setUserResolver(Closure $callback): static
    {
        $this->userResolver = $callback;

        return $this;
    }

    /**
     * Get the route resolver callback.
     */
    public function getRouteResolver(): Closure
    {
        return $this->routeResolver ?: function () {
        };
    }

    /**
     * Set the route resolver callback.
     *
     * @return $this
     */
    public function setRouteResolver(Closure $callback): static
    {
        $this->routeResolver = $callback;

        return $this;
    }

    /**
     * Get all of the input and files for the request.
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        $route = $this->route();

        return Arr::has(
            $this->all() + ($route ? $route->parameters() : []),
            $offset
        );
    }

    /**
     * Get the value at the given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    /**
     * Set the value at the given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->getInputSource()->set($offset, $value);
    }

    /**
     * Remove the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->getInputSource()->remove($offset);
    }

    /**
     * Check if an input element is set on the request.
     */
    public function __isset(string $key): bool
    {
        return ! is_null($this->__get($key));
    }

    /**
     * Get an input element from the request.
     */
    public function __get(string $key): mixed
    {
        return Arr::get($this->all(), $key, fn () => $this->route($key));
    }
}
