<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Request as HyperfRequest;
use Hyperf\Stringable\Str;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Router\Contracts\UrlGenerator as UrlGeneratorContract;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Support\Collection;
use Hypervel\Validation\Contracts\Factory as ValidatorFactoryContract;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use stdClass;
use Stringable;
use TypeError;

use function Hyperf\Collection\data_get;

class Request extends HyperfRequest implements RequestContract
{
    /**
     * The user resolver callback.
     */
    protected ?Closure $userResolver = null;

    /**
     * Retrieve normalized file upload data.
     * This method returns upload metadata in a normalized tree, with each leaf
     * an instance of Psr\Http\Message\UploadedFileInterface.
     * These values MAY be prepared from $_FILES or the message body during
     * instantiation, or MAY be injected via withUploadedFiles().
     *
     * @return array an array tree of UploadedFileInterface instances; an empty
     *               array MUST be returned if no data is present
     */
    public function allFiles(): array
    {
        return $this->getUploadedFiles();
    }

    /**
     * Determine if the request contains a non-empty value for any of the given inputs.
     */
    public function anyFilled(array|string $keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        foreach ($keys as $key) {
            if ($this->filled($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve input as a boolean value.
     *
     * Returns true when value is "1", "true", "on", and "yes". Otherwise, returns false.
     */
    public function boolean(?string $key = null, bool $default = false): bool
    {
        return filter_var(
            $this->input($key, $default),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Retrieve input from the request as a collection.
     */
    public function collect(null|array|string $key = null): Collection
    {
        if (is_null($key)) {
            return Collection::make($this->all());
        }

        return Collection::make(
            is_array($key) ? $this->only($key) : $this->input($key)
        );
    }

    /**
     * Retrieve input from the request as a Carbon instance.
     *
     * @throws InvalidFormatException
     */
    public function date(string $key, ?string $format = null, ?string $tz = null): ?Carbon
    {
        if ($this->isNotFilled($key)) {
            return null;
        }

        if (is_null($format)) {
            return Carbon::parse($this->input($key), $tz);
        }

        return Carbon::createFromFormat($format, $this->input($key), $tz);
    }

    /**
     * Retrieve input from the request as an enum.
     *
     * @template TEnum
     *
     * @param class-string<TEnum> $enumClass
     * @return null|TEnum
     */
    public function enum(string $key, $enumClass)
    {
        if ($this->isNotFilled($key)
            || ! function_exists('enum_exists')
            || ! enum_exists($enumClass)
            || ! method_exists($enumClass, 'tryFrom')
        ) {
            return null;
        }

        return $enumClass::tryFrom($this->input($key));
    }

    /**
     * Get all of the input except for a specified array of items.
     *
     * @param array|mixed $keys
     */
    public function except(mixed $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $results = $this->all();

        Arr::forget($results, $keys);

        return $results;
    }

    /**
     * Determine if the request contains a given input item key.
     */
    public function exists(array|string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Determine if the request contains a non-empty value for an input item.
     */
    public function filled(array|string $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if ($this->isEmptyString($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve input as a float value.
     */
    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    /**
     * Retrieve input from the request as a Stringable instance.
     */
    public function string(string $key, mixed $default = null): Stringable
    {
        return Str::of($this->input($key, $default));
    }

    /**
     * Retrieve input from the request as a Stringable instance.
     */
    public function str(string $key, mixed $default = null): Stringable
    {
        return $this->string($key, $default = null);
    }

    /**
     * Determine if the request contains any of the given inputs.
     */
    public function hasAny(array|string $keys): bool
    {
        return Arr::hasAny(
            $this->all(),
            is_array($keys) ? $keys : func_get_args()
        );
    }

    /**
     * Returns the host name.
     *
     * This method can read the client host name from the "HOST" header
     * or SERVER_NAME from server params, or SERVER_ADDR from server params
     */
    public function getHost(): string
    {
        $host = $this->header('HOST') ?? $this->server('SERVER_NAME') ?? $this->server('SERVER_ADDR') ?? '';

        return strtolower(preg_replace('/:\d+$/', '', trim($host)));
    }

    /**
     * Returns the HTTP host being requested.
     *
     * The port name will be appended to the host if it's non-standard.
     */
    public function getHttpHost(): string
    {
        return $this->getHost() . ':' . $this->getPort();
    }

    /**
     * Returns the port on which the request is made.
     *
     * This method can read the client port from the SERVER_PORT from server params
     * or from HTTP scheme.
     *
     * @return int|string Can be a string if fetched from the server bag
     */
    public function getPort(): int|string
    {
        if (! $host = $this->header('HOST', '')) {
            return $this->server('SERVER_PORT');
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
     * Gets the request's scheme.
     */
    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * Checks whether the request is secure or not.
     *
     * This method can read the client protocol from the HTTPS from server params.
     */
    public function isSecure(): bool
    {
        $https = $this->server('HTTPS', '');

        return ! empty($https) && strtolower($https) !== 'off';
    }

    /**
     * Retrieve input as an integer value.
     */
    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    /**
     * Determine if the given input key is an empty string for "filled".
     */
    public function isEmptyString(string $key): bool
    {
        $value = $this->input($key);

        return ! is_bool($value)
            && ! is_array($value)
            && trim((string) $value) === '';
    }

    /**
     * Determine if the request is sending JSON.
     */
    public function isJson(): bool
    {
        return Str::contains($this->header('CONTENT_TYPE') ?? '', ['/json', '+json']);
    }

    /**
     * Determine if the request contains an empty value for an input item.
     */
    public function isNotFilled(array|string $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if (! $this->isEmptyString($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the keys for all of the input and files.
     */
    public function keys(): array
    {
        return array_merge(
            array_keys($this->all()),
            array_keys($this->getUploadedFiles())
        );
    }

    /**
     * Merge new input into the current request's input array.
     *
     * @return $this
     */
    public function merge(array $input): static
    {
        Context::override(
            $this->contextkeys['parsedData'],
            fn ($inputs) => array_merge((array) $inputs, $input)
        );

        return $this;
    }

    /**
     * Replace the input values for the current request.
     *
     * @return $this
     */
    public function replace(array $input): static
    {
        Context::override(
            $this->contextkeys['parsedData'],
            fn ($inputs) => array_replace((array) $inputs, $input)
        );

        return $this;
    }

    /**
     * Merge new input into the request's input, but only when that key is missing from the request.
     *
     * @return $this
     */
    public function mergeIfMissing(array $input): static
    {
        return $this->merge(
            Collection::make($input)
                ->filter(fn ($value, $key) => $this->missing($key))
                ->toArray()
        );
    }

    /**
     * Determine if the request is missing a given input item key.
     */
    public function missing(array|string $key): bool
    {
        return ! $this->has(is_array($key) ? $key : func_get_args());
    }

    /**
     * Get a subset containing the provided keys with values from the input data.
     *
     * @param array|mixed $keys
     */
    public function only(mixed $keys): array
    {
        $results = [];
        $input = $this->all();
        $placeholder = new stdClass();

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            $value = data_get($input, $key, $placeholder);

            if ($value !== $placeholder) {
                Arr::set($results, $key, $value);
            }
        }

        return $results;
    }

    /**
     * Get all the input and files for the request.
     */
    public function all(mixed $keys = null): array
    {
        $input = array_replace_recursive($this->getInputData(), $this->allFiles());

        if (! $keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * Gets the scheme and HTTP host.
     *
     * If the URL was called with basic authentication, the user
     * and the password are not added to the generated string.
     */
    public function getSchemeAndHttpHost(): string
    {
        return $this->getScheme() . '://' . $this->getHttpHost();
    }

    /**
     * Get the scheme and HTTP host.
     */
    public function schemeAndHttpHost(): string
    {
        return $this->getSchemeAndHttpHost();
    }

    /**
     * Determine if the current request probably expects a JSON response.
     */
    public function expectsJson(): bool
    {
        return ($this->ajax() && ! $this->pjax() && $this->acceptsAnyContentType())
            || $this->wantsJson();
    }

    /**
     * Determine if the current request is asking for JSON.
     */
    public function wantsJson(): bool
    {
        $acceptable = explode(',', $this->header('Accept') ?? '');

        return Str::contains(strtolower($acceptable[0]) ?? '', ['/json', '+json']);
    }

    /**
     * Determines whether the current requests accepts a given content type.
     */
    public function accepts(array|string $contentTypes): bool
    {
        $accepts = $this->getAcceptableContentTypes();
        if (count($accepts) === 0) {
            return true;
        }
        $contentTypes = is_string($contentTypes) ? [$contentTypes] : $contentTypes;
        foreach ($accepts as $accept) {
            if ($accept === '*/*' || $accept === '*') {
                return true;
            }

            foreach ($contentTypes as $type) {
                $accept = strtolower($accept);
                $type = strtolower($type);

                if (AcceptHeader::matchesType($accept, $type) || $accept === strtok($type, '/') . '/*') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the most suitable content type from the given array based on content negotiation.
     */
    public function prefers(array|string $contentTypes): ?string
    {
        $accepts = $this->getAcceptableContentTypes();
        foreach ($accepts as $accept) {
            if (in_array($accept, ['*/*', '*'])) {
                return $contentTypes[0];
            }

            foreach ($contentTypes as $contentType) {
                $type = $contentType;

                if (! is_null($mimeType = $this->getMimeType($contentType))) {
                    $type = $mimeType;
                }

                $accept = strtolower($accept);

                $type = strtolower($type);

                if (AcceptHeader::matchesType($type, $accept) || $accept === strtok($type, '/') . '/*') {
                    return $contentType;
                }
            }
        }

        return null;
    }

    /**
     * Determine if the current request accepts any content type.
     */
    public function acceptsAnyContentType(): bool
    {
        $acceptable = $this->getAcceptableContentTypes();

        return count($acceptable) === 0 || (
            isset($acceptable[0]) && ($acceptable[0] === '*/*' || $acceptable[0] === '*')
        );
    }

    /**
     * Determines whether a request accepts JSON.
     */
    public function acceptsJson(): bool
    {
        return $this->accepts('application/json');
    }

    /**
     * Determines whether a request accepts HTML.
     */
    public function acceptsHtml(): bool
    {
        return $this->accepts('text/html');
    }

    /**
     * Apply the callback if the request contains a non-empty value for the given input item key.
     *
     * @return $this|mixed
     */
    public function whenFilled(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->filled($key)) {
            return $callback(data_get($this->all(), $key)) ?: $this;
        }

        if ($default) {
            return $default();
        }

        return $this;
    }

    /**
     * Apply the callback if the request contains the given input item key.
     *
     * @return $this|mixed
     */
    public function whenHas(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->has($key)) {
            return $callback(data_get($this->all(), $key)) ?: $this;
        }

        if ($default) {
            return $default();
        }

        return $this;
    }

    /**
     * Returns the client IP address.
     *
     * This method can read the client IP address from the "x-real-ip" header
     * or remote_addr from server params.
     */
    public function getClientIp(): ?string
    {
        if (! RequestContext::has()) {
            return '127.0.0.1';
        }

        return $this->getHeaderLine('x-real-ip')
            ?: $this->server('remote_addr');
    }

    /**
     * Returns the client IP address.
     *
     * This method can read the client IP address from the "x-real-ip" header
     * or remote_addr from server params.
     *
     * alias of getClientIp
     */
    public function ip(): ?string
    {
        return $this->getClientIp();
    }

    /**
     * Get the root URL for the application.
     */
    public function root(): string
    {
        return rtrim($this->getSchemeAndHttpHost(), '/');
    }

    /**
     * Get the full URL for the request.
     */
    public function fullUrl(): string
    {
        $url = $this->url();
        if ($query = $this->getQueryString()) {
            $url .= '?' . $query;
        }

        return $url;
    }

    /**
     * Get the full URL for the request with the added query string parameters.
     */
    public function fullUrlWithQuery(array $query): string
    {
        $question = $this->url() . $this->getPathInfo() === '/' ? '/?' : '?';

        return count($this->query()) > 0
            ? $this->url() . $question . Arr::query(array_merge($this->query(), $query))
            : $this->fullUrl() . Arr::query($query);
    }

    /**
     * Get the full URL for the request without the given query string parameters.
     */
    public function fullUrlWithoutQuery(array|string $keys): string
    {
        $query = Arr::except($this->query(), $keys);

        $question = $this->url() . $this->getPathInfo() === '/' ? '/?' : '?';

        return count($query) > 0
            ? $this->url() . $question . Arr::query($query)
            : $this->url();
    }

    /**
     * Get the request method.
     */
    public function method(): string
    {
        return $this->getMethod();
    }

    /**
     * Get the request headers.
     */
    public function headers(): array
    {
        return $this->getHeaders();
    }

    /**
     * Get the bearer token from the request headers.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');

        $position = strrpos($header, 'Bearer ');

        if ($position !== false) {
            $header = substr($header, $position + 7);

            return str_contains($header, ',') ? strstr($header, ',', true) : $header;
        }

        return null;
    }

    /**
     * Gets a list of content types acceptable by the client browser in preferable order.
     *
     * @return string[]
     */
    public function getAcceptableContentTypes(): array
    {
        return array_map('strval', array_keys(
            AcceptHeader::fromString($this->header('Accept'))->all()
        ));
    }

    /**
     * Gets the mime type associated with the format.
     */
    public function getMimeType(string $format): ?string
    {
        return isset(HeaderUtils::$formats[$format]) ? HeaderUtils::$formats[$format][0] : null;
    }

    /**
     * Gets the mime types associated with the format.
     *
     * @return string[]
     */
    public function getMimeTypes(string $format): array
    {
        return HeaderUtils::$formats[$format] ?? [];
    }

    /**
     * Returns true if the request is an XMLHttpRequest.
     *
     * It works if your JavaScript library sets an X-Requested-With HTTP header.
     * It is known to work with common JavaScript frameworks:
     *
     * @see https://wikipedia.org/wiki/List_of_Ajax_frameworks#JavaScript
     */
    public function isXmlHttpRequest(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Determine if the request is the result of an AJAX call.
     *
     * alias of isXmlHttpRequest
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
        return $this->header('X-PJAX') === 'true';
    }

    /**
     * Determine if the request is the result of a prefetch call.
     */
    public function prefetch(): bool
    {
        return strcasecmp($this->server('HTTP_X_MOZ') ?? '', 'prefetch') === 0
            || strcasecmp($this->header('Purpose') ?? '', 'prefetch') === 0
            || strcasecmp($this->header('Sec-Purpose') ?? '', 'prefetch') === 0;
    }

    /**
     * Determine if the it is a range request.
     */
    public function isRange(): bool
    {
        if (! $this->hasHeader('Range') || $this->getMethod() !== 'GET') {
            return false;
        }

        if (! str_starts_with($this->header('Range'), 'bytes=')) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the request has a session.
     */
    public function hasSession(): bool
    {
        return ApplicationContext::getContainer()
            ->has(SessionContract::class);
    }

    /**
     * Get session for the current request.
     */
    public function session(): SessionContract
    {
        return ApplicationContext::getContainer()
            ->get(SessionContract::class);
    }

    /**
     * Validate the given data against the provided rules.
     *
     * @throws \Hypervel\Validation\ValidationException
     */
    public function validate(array $rules, array $messages = [], array $customAttributes = []): array
    {
        return ApplicationContext::getContainer()
            ->get(ValidatorFactoryContract::class)
            ->validate($this->all(), $rules, $messages, $customAttributes);
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
     */
    public function setUserResolver(Closure $callback): static
    {
        $this->userResolver = $callback;

        return $this;
    }

    /**
     * Get the user making the request.
     */
    public function user(?string $guard = null): mixed
    {
        return call_user_func($this->getUserResolver(), $guard);
    }

    /**
     * Check if the current request url has valid signature.
     */
    public function hasValidSignature(bool $absolute = true): bool
    {
        return ApplicationContext::getContainer()
            ->get(UrlGeneratorContract::class)
            ->hasValidSignature($this, $absolute);
    }

    /**
     * Check if the current request url has relative signature.
     */
    public function hasValidRelativeSignature(): bool
    {
        return ApplicationContext::getContainer()
            ->get(UrlGeneratorContract::class)
            ->hasValidSignature($this, false);
    }

    /**
     * Check if the current request url has valid signature wile ignoring.
     */
    public function hasValidSignatureWhileIgnoring(array $ignoreQuery = [], bool $absolute = true): bool
    {
        return ApplicationContext::getContainer()
            ->get(UrlGeneratorContract::class)
            ->hasValidSignature($this, $absolute, $ignoreQuery);
    }

    /**
     * Check if the current request url has valid relative signature wile ignoring.
     */
    public function hasValidRelativeSignatureWhileIgnoring(array $ignoreQuery = []): bool
    {
        return ApplicationContext::getContainer()
            ->get(UrlGeneratorContract::class)
            ->hasValidSignature($this, false, $ignoreQuery);
    }

    /**
     * Get original psr7 request instance.
     */
    public function getPsr7Request(): ServerRequestInterface
    {
        return $this->getRequest();
    }

    protected function getRequest(): ServerRequestInterface
    {
        try {
            return RequestContext::get();
        } catch (TypeError $e) {
            if (! RequestContext::has()) {
                throw new RuntimeException(
                    'RequestContext is not set, please use RequestContext::set() to set the request.'
                );
            }

            throw $e;
        }
    }
}
