<?php

declare(strict_types=1);

namespace Hypervel\Http;

use ArrayAccess;
use Closure;
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
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @method array validate(array $rules, ...$params)
 * @method array validateWithBag(string $errorBag, array $rules, ...$params)
 * @method bool hasValidSignature(bool $absolute = true)
 * @method bool hasValidRelativeSignature()
 * @method bool hasValidSignatureWhileIgnoring($ignoreQuery = [], $absolute = true)
 * @method bool hasValidRelativeSignatureWhileIgnoring($ignoreQuery = [])
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
     * This method belongs to Symfony HttpFoundation and is not usually needed when using Hypervel.
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
        $request = $to ?: new static();

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
            $request->cookies->all(), (new static())->filterFiles($request->files->all()) ?? [], $request->server->all()
        );

        $newRequest->headers->replace($request->headers->all());

        $newRequest->content = $request->content;

        if ($newRequest->isJson()) {
            $newRequest->request = $newRequest->json();
        }

        return $newRequest;
    }

    #[Override]
    public function duplicate(?array $query = null, ?array $request = null, ?array $attributes = null, ?array $cookies = null, ?array $files = null, ?array $server = null): static
    {
        return parent::duplicate($query, $request, $attributes, $cookies, $this->filterFiles($files), $server);
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
            : throw new SessionNotFoundException();
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

        return $route->parameter($param, $default);
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
