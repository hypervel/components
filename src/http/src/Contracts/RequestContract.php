<?php

declare(strict_types=1);

namespace Hypervel\Http\Contracts;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Session\Contracts\Session as SessionContract;
use Hypervel\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;

interface RequestContract extends RequestInterface
{
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
    public function allFiles(): array;

    /**
     * Determine if the request contains a non-empty value for any of the given inputs.
     */
    public function anyFilled(array|string $keys): bool;

    /**
     * Retrieve input as a boolean value.
     *
     * Returns true when value is "1", "true", "on", and "yes". Otherwise, returns false.
     */
    public function boolean(?string $key = null, bool $default = false): bool;

    /**
     * Retrieve input from the request as a collection.
     */
    public function collect(null|array|string $key = null): Collection;

    /**
     * Retrieve input from the request as a Carbon instance.
     *
     * @throws InvalidFormatException
     */
    public function date(string $key, ?string $format = null, ?string $tz = null): ?Carbon;

    /**
     * Retrieve input from the request as an enum.
     *
     * @template TEnum
     *
     * @param class-string<TEnum> $enumClass
     * @return null|TEnum
     */
    public function enum(string $key, $enumClass);

    /**
     * Get all of the input except for a specified array of items.
     *
     * @param array|mixed $keys
     */
    public function except(mixed $keys): array;

    /**
     * Determine if the request contains a given input item key.
     */
    public function exists(array|string $key): bool;

    /**
     * Determine if the request contains a non-empty value for an input item.
     */
    public function filled(array|string $key): bool;

    /**
     * Retrieve input as a float value.
     */
    public function float(string $key, float $default = 0.0): float;

    /**
     * Retrieve input from the request as a Stringable instance.
     */
    public function string(string $key, mixed $default = null): Stringable;

    /**
     * Retrieve input from the request as a Stringable instance.
     */
    public function str(string $key, mixed $default = null): Stringable;

    /**
     * Determine if the request contains any of the given inputs.
     */
    public function hasAny(array|string $keys): bool;

    /**
     * Returns the host name.
     *
     * This method can read the client host name from the "HOST" header
     * or SERVER_NAME from server params, or SERVER_ADDR from server params
     */
    public function getHost(): string;

    /**
     * Returns the HTTP host being requested.
     *
     * The port name will be appended to the host if it's non-standard.
     */
    public function getHttpHost(): string;

    /**
     * Returns the port on which the request is made.
     *
     * This method can read the client port from the SERVER_PORT from server params
     * or from HTTP scheme.
     *
     * @return int|string Can be a string if fetched from the server bag
     */
    public function getPort(): int|string;

    /**
     * Gets the request's scheme.
     */
    public function getScheme(): string;

    /**
     * Checks whether the request is secure or not.
     *
     * This method can read the client protocol from the HTTPS from server params.
     */
    public function isSecure(): bool;

    /**
     * Retrieve input as an integer value.
     */
    public function integer(string $key, int $default = 0): int;

    /**
     * Determine if the given input key is an empty string for "filled".
     */
    public function isEmptyString(string $key): bool;

    /**
     * Determine if the request is sending JSON.
     */
    public function isJson(): bool;

    /**
     * Determine if the request contains an empty value for an input item.
     */
    public function isNotFilled(array|string $key): bool;

    /**
     * Get the keys for all of the input and files.
     */
    public function keys(): array;

    /**
     * Merge new input into the current request's input array.
     *
     * @return $this
     */
    public function merge(array $input): static;

    /**
     * Replace the input values for the current request.
     *
     * @return $this
     */
    public function replace(array $input): static;

    /**
     * Merge new input into the request's input, but only when that key is missing from the request.
     *
     * @return $this
     */
    public function mergeIfMissing(array $input);

    /**
     * Determine if the request is missing a given input item key.
     */
    public function missing(array|string $key): bool;

    /**
     * Get a subset containing the provided keys with values from the input data.
     *
     * @param array|mixed $keys
     */
    public function only(mixed $keys): array;

    /**
     * Gets the scheme and HTTP host.
     *
     * If the URL was called with basic authentication, the user
     * and the password are not added to the generated string.
     */
    public function getSchemeAndHttpHost(): string;

    /**
     * Get the scheme and HTTP host.
     */
    public function schemeAndHttpHost(): string;

    /**
     * Determine if the current request probably expects a JSON response.
     */
    public function expectsJson(): bool;

    /**
     * Determine if the current request is asking for JSON.
     */
    public function wantsJson(): bool;

    /**
     * Determines whether the current requests accepts a given content type.
     */
    public function accepts(array|string $contentTypes): bool;

    /**
     * Return the most suitable content type from the given array based on content negotiation.
     */
    public function prefers(array|string $contentTypes): ?string;

    /**
     * Determine if the current request accepts any content type.
     */
    public function acceptsAnyContentType(): bool;

    /**
     * Determines whether a request accepts JSON.
     */
    public function acceptsJson(): bool;

    /**
     * Determines whether a request accepts HTML.
     */
    public function acceptsHtml(): bool;

    /**
     * Apply the callback if the request contains a non-empty value for the given input item key.
     *
     * @return $this|mixed
     */
    public function whenFilled(string $key, callable $callback, ?callable $default = null): mixed;

    /**
     * Apply the callback if the request contains the given input item key.
     *
     * @return $this|mixed
     */
    public function whenHas(string $key, callable $callback, ?callable $default = null): mixed;

    /**
     * Returns the client IP address.
     *
     * This method can read the client IP address from the "x-real-ip" header
     * or remote_addr from server params.
     */
    public function getClientIp(): ?string;

    /**
     * Returns the client IP address.
     *
     * This method can read the client IP address from the "x-real-ip" header
     * or remote_addr from server params.
     *
     * alias of getClientIp
     */
    public function ip(): ?string;

    /**
     * Get the full URL for the request with the added query string parameters.
     */
    public function fullUrlWithQuery(array $query): string;

    /**
     * Get the root URL for the application.
     */
    public function root(): string;

    /**
     * Get the full URL for the request without the given query string parameters.
     */
    public function fullUrlWithoutQuery(array|string $keys): string;

    /**
     * Get the request method.
     */
    public function method(): string;

    /**
     * Get the request headers.
     */
    public function headers(): array;

    /**
     * Get the bearer token from the request headers.
     */
    public function bearerToken(): ?string;

    /**
     * Gets a list of content types acceptable by the client browser in preferable order.
     *
     * @return string[]
     */
    public function getAcceptableContentTypes(): array;

    /**
     * Gets the mime type associated with the format.
     */
    public function getMimeType(string $format): ?string;

    /**
     * Gets the mime types associated with the format.
     *
     * @return string[]
     */
    public function getMimeTypes(string $format): array;

    /**
     * Returns true if the request is an XMLHttpRequest.
     *
     * It works if your JavaScript library sets an X-Requested-With HTTP header.
     * It is known to work with common JavaScript frameworks:
     *
     * @see https://wikipedia.org/wiki/List_of_Ajax_frameworks#JavaScript
     */
    public function isXmlHttpRequest(): bool;

    /**
     * Determine if the request is the result of an AJAX call.
     *
     * alias of isXmlHttpRequest
     */
    public function ajax(): bool;

    /**
     * Determine if the request is the result of a PJAX call.
     */
    public function pjax(): bool;

    /**
     * Determine if the request is the result of a prefetch call.
     */
    public function prefetch(): bool;

    /**
     * Determine if the it is a range request.
     */
    public function isRange(): bool;

    /**
     * Determine if the request has a session.
     */
    public function hasSession(): bool;

    /**
     * Get session for the current request.
     */
    public function session(): SessionContract;

    /**
     * Validate the given data against the provided rules.
     *
     * @throws \Hypervel\Validation\ValidationException
     */
    public function validate(array $rules, array $messages = [], array $customAttributes = []): array;

    /**
     * Get the user resolver callback.
     */
    public function getUserResolver(): Closure;

    /**
     * Set the user resolver callback.
     */
    public function setUserResolver(Closure $callback): static;

    /**
     * Get the user making the request.
     */
    public function user(?string $guard = null): mixed;

    /**
     * Check if the current request url has valid signature.
     */
    public function hasValidSignature(bool $absolute = true): bool;

    /**
     * Check if the current request url has relative signature.
     */
    public function hasValidRelativeSignature(): bool;

    /**
     * Check if the current request url has valid signature wile ignoring.
     */
    public function hasValidSignatureWhileIgnoring(array $ignoreQuery = [], bool $absolute = true): bool;

    /**
     * Check if the current request url has valid relative signature wile ignoring.
     */
    public function hasValidRelativeSignatureWhileIgnoring(array $ignoreQuery = []): bool;

    /**
     * Get original psr7 request instance.
     */
    public function getPsr7Request(): ServerRequestInterface;
}
