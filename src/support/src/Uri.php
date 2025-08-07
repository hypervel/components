<?php

declare(strict_types=1);

namespace Hypervel\Support;

use BackedEnum;
use Closure;
use DateInterval;
use DateTimeInterface;
// use Illuminate\Contracts\Routing\UrlRoutable;
// use Illuminate\Contracts\Support\Htmlable;
// use Illuminate\Contracts\Support\Responsable;
// use Illuminate\Http\RedirectResponse;
// use Illuminate\Support\Traits\Conditionable;
// use Illuminate\Support\Traits\Dumpable;
// use Illuminate\Support\Traits\Macroable;
// use Illuminate\Support\Traits\Tappable;
use Hyperf\HttpMessage\Server\Response;
use Hypervel\Router\Contracts\UrlRoutable;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\Support\Contracts\Responsable;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Dumpable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use InvalidArgumentException;
use JsonSerializable;
use League\Uri\Contracts\UriInterface;
use League\Uri\Uri as LeagueUri;
use Psr\Http\Message\ResponseInterface;
use Stringable;

class Uri implements Htmlable, JsonSerializable, Responsable, Stringable
{
    use Conditionable;
    use Dumpable;
    use Macroable;
    use Tappable;

    /**
     * The URI instance.
     */
    protected UriInterface $uri;

    /**
     * The URL generator resolver.
     */
    protected static ?Closure $urlGeneratorResolver = null;

    /**
     * Create a new parsed URI instance.
     */
    public function __construct(string|Stringable|UriInterface $uri = '')
    {
        $this->uri = $uri instanceof UriInterface ? $uri : LeagueUri::new((string) $uri);
    }

    /**
     * Create a new URI instance.
     */
    public static function of(string|Stringable|UriInterface $uri = ''): static
    {
        return new static($uri);
    }

    /**
     * Get a URI instance of an absolute URL for the given path.
     */
    public static function to(string $path): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->to($path));
    }

    /**
     * Get a URI instance for a named route.
     *
     * @throws InvalidArgumentException
     */
    public static function route(BackedEnum|string $name, array $parameters = [], bool $absolute = true): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->route($name, $parameters, $absolute));
    }

    /**
     * Create a signed route URI instance for a named route.
     *
     * @throws InvalidArgumentException
     */
    public static function signedRoute(BackedEnum|string $name, array $parameters = [], null|DateInterval|DateTimeInterface|int $expiration = null, bool $absolute = true): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->signedRoute($name, $parameters, $expiration, $absolute));
    }

    /**
     * Create a temporary signed route URI instance for a named route.
     */
    public static function temporarySignedRoute(BackedEnum|string $name, null|DateInterval|DateTimeInterface|int $expiration = null, array $parameters = [], bool $absolute = true): static
    {
        return static::signedRoute($name, $parameters, $expiration, $absolute);
    }

    /**
     * Get a URI instance for a controller action.
     *
     * @throws InvalidArgumentException
     */
    public static function action(array|string $action, array $parameters = [], bool $absolute = true): static
    {
        return new static(call_user_func(static::$urlGeneratorResolver)->action($action, $parameters, $absolute));
    }

    /**
     * Get the URI's scheme.
     */
    public function scheme(): ?string
    {
        return $this->uri->getScheme();
    }

    /**
     * Get the user from the URI.
     */
    public function user(bool $withPassword = false): ?string
    {
        return $withPassword
            ? $this->uri->getUserInfo()
            : $this->uri->getUsername();
    }

    /**
     * Get the password from the URI.
     */
    public function password(): ?string
    {
        return $this->uri->getPassword();
    }

    /**
     * Get the URI's host.
     */
    public function host(): ?string
    {
        return $this->uri->getHost();
    }

    /**
     * Get the URI's port.
     */
    public function port(): ?int
    {
        return $this->uri->getPort();
    }

    /**
     * Get the URI's path.
     *
     * Empty or missing paths are returned as a single "/".
     */
    public function path(): ?string
    {
        $path = trim((string) $this->uri->getPath(), '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Get the URI's path segments.
     *
     * Empty or missing paths are returned as an empty collection.
     */
    public function pathSegments(): Collection
    {
        $path = $this->path();

        return $path === '/' ? new Collection() : new Collection(explode('/', $path));
    }

    /**
     * Get the URI's query string.
     */
    public function query(): UriQueryString
    {
        return new UriQueryString($this);
    }

    /**
     * Get the URI's fragment.
     */
    public function fragment(): ?string
    {
        return $this->uri->getFragment();
    }

    /**
     * Specify the scheme of the URI.
     */
    public function withScheme(string|Stringable $scheme): static
    {
        return new static($this->uri->withScheme($scheme));
    }

    /**
     * Specify the user and password for the URI.
     */
    public function withUser(null|string|Stringable $user, null|string|Stringable $password = null): static
    {
        return new static($this->uri->withUserInfo($user, $password));
    }

    /**
     * Specify the host of the URI.
     */
    public function withHost(string|Stringable $host): static
    {
        return new static($this->uri->withHost($host));
    }

    /**
     * Specify the port of the URI.
     */
    public function withPort(?int $port): static
    {
        return new static($this->uri->withPort($port));
    }

    /**
     * Specify the path of the URI.
     */
    public function withPath(string|Stringable $path): static
    {
        return new static($this->uri->withPath(Str::start((string) $path, '/')));
    }

    /**
     * Merge new query parameters into the URI.
     */
    public function withQuery(array $query, bool $merge = true): static
    {
        foreach ($query as $key => $value) {
            if ($value instanceof UrlRoutable) {
                $query[$key] = $value->getRouteKey();
            }
        }

        if ($merge) {
            $mergedQuery = $this->query()->all();

            foreach ($query as $key => $value) {
                data_set($mergedQuery, $key, $value);
            }

            $newQuery = $mergedQuery;
        } else {
            $newQuery = [];

            foreach ($query as $key => $value) {
                data_set($newQuery, $key, $value);
            }
        }

        return new static($this->uri->withQuery(Arr::query($newQuery) ?: null));
    }

    /**
     * Merge new query parameters into the URI if they are not already in the query string.
     */
    public function withQueryIfMissing(array $query): static
    {
        $currentQuery = $this->query();

        foreach ($query as $key => $value) {
            if (! $currentQuery->missing($key)) {
                Arr::forget($query, $key);
            }
        }

        return $this->withQuery($query);
    }

    /**
     * Push a value onto the end of a query string parameter that is a list.
     */
    public function pushOntoQuery(string $key, mixed $value): static
    {
        $currentValue = data_get($this->query()->all(), $key);

        $values = Arr::wrap($value);

        return $this->withQuery([$key => match (true) {
            is_array($currentValue) && array_is_list($currentValue) => array_values(array_unique([...$currentValue, ...$values])),
            is_array($currentValue) => [...$currentValue, ...$values],
            ! is_null($currentValue) => [$currentValue, ...$values],
            default => $values,
        }]);
    }

    /**
     * Remove the given query parameters from the URI.
     */
    public function withoutQuery(array|string $keys): static
    {
        return $this->replaceQuery(Arr::except($this->query()->all(), $keys));
    }

    /**
     * Specify new query parameters for the URI.
     */
    public function replaceQuery(array $query): static
    {
        return $this->withQuery($query, merge: false);
    }

    /**
     * Specify the fragment of the URI.
     */
    public function withFragment(string $fragment): static
    {
        return new static($this->uri->withFragment($fragment));
    }

    /**
     * Create a redirect HTTP response for the given URI.
     */
    public function redirect(int $status = 302, array $headers = []): ResponseInterface
    {
        $response = (new Response())
            ->withStatus($status)
            ->withHeader('Location', $this->value());

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
        // $toUrl = value(function () use ($toUrl, $schema) {
        //     if (Str::startsWith($toUrl, ['http://', 'https://'])) {
        //         return $toUrl;
        //     }

        //     $host = RequestContext::get()->getUri()->getAuthority();

        //     // Build the url by $schema and host.
        //     return $schema . '://' . $host . (Str::startsWith($toUrl, '/') ? $toUrl : '/' . $toUrl);
        // });
        // return $this->getResponse()->withStatus($status)->withAddedHeader('Location', $toUrl);
        // app(ResponseContract::class)
        //     ->redirect($toUrl, $status, $schema)
        // return new RedirectResponse($this->value(), $status, $headers);
    }

    /**
     * Get the URI as a Stringable instance.
     */
    public function toStringable(): Stringable
    {
        return Str::of($this->value());
    }

    /**
     * Create an HTTP response that represents the URI object.
     * @param mixed $request
     */
    public function toResponse($request): ResponseInterface
    {
        return $this->redirect();
    }

    /**
     * Get the URI as a string of HTML.
     */
    public function toHtml(): string
    {
        return $this->value();
    }

    /**
     * Get the decoded string representation of the URI.
     */
    public function decode(): string
    {
        if (empty($this->query()->toArray())) {
            return $this->value();
        }

        return Str::replace(Str::after($this->value(), '?'), $this->query()->decode(), $this->value());
    }

    /**
     * Get the string representation of the URI.
     */
    public function value(): string
    {
        return (string) $this;
    }

    /**
     * Determine if the URI is currently an empty string.
     */
    public function isEmpty(): bool
    {
        return trim($this->value()) === '';
    }

    /**
     * Dump the string representation of the URI.
     */
    public function dump(mixed ...$args): static
    {
        dump($this->value(), ...$args);

        return $this;
    }

    /**
     * Set the URL generator resolver.
     */
    public static function setUrlGeneratorResolver(Closure $urlGeneratorResolver): void
    {
        static::$urlGeneratorResolver = $urlGeneratorResolver;
    }

    /**
     * Get the underlying URI instance.
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * Convert the object into a value that is JSON serializable.
     */
    public function jsonSerialize(): string
    {
        return $this->value();
    }

    /**
     * Get the string representation of the URI.
     */
    public function __toString(): string
    {
        return $this->uri->toString();
    }
}
