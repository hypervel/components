<?php

declare(strict_types=1);

namespace Hypervel\Router\Contracts;

use BackedEnum;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use InvalidArgumentException;

interface UrlGenerator
{
    /**
     * Get the URL to a named route.
     *
     * @throws InvalidArgumentException
     */
    public function route(string $name, array $parameters = [], bool $absolute = true, string $server = 'http'): string;

    /**
     * Generate a url for the application.
     */
    public function to(string $path, array $extra = [], ?bool $secure = null): string;

    /**
     * Generate an absolute URL with the given query parameters.
     */
    public function query(string $path, array $query = [], array $extra = [], ?bool $secure = null): string;

    /**
     * Generate a secure, absolute URL to the given path.
     */
    public function secure(string $path, array $extra = []): string;

    /**
     * Generate the URL to an application asset.
     */
    public function asset(string $path, ?bool $secure = null): string;

    /**
     * Generate the URL to a secure asset.
     */
    public function secureAsset(string $path): string;

    /**
     * Generate the URL to an asset from a custom root domain such as CDN, etc.
     */
    public function assetFrom(string $root, string $path, ?bool $secure = null): string;

    /**
     * Get the default scheme for a raw URL.
     */
    public function formatScheme(?bool $secure = null): string;

    /**
     * Create a signed route URL for a named route.
     *
     * @throws InvalidArgumentException
     */
    public function signedRoute(BackedEnum|string $name, array $parameters = [], null|DateInterval|DateTimeInterface|int $expiration = null, bool $absolute = true, string $server = 'http'): string;

    /**
     * Create a temporary signed route URL for a named route.
     */
    public function temporarySignedRoute(BackedEnum|string $name, null|DateInterval|DateTimeInterface|int $expiration, array $parameters = [], bool $absolute = true, string $server = 'http'): string;

    /**
     * Determine if the given request has a valid signature.
     */
    public function hasValidSignature(RequestInterface $request, bool $absolute = true, array $ignoreQuery = []): bool;

    /**
     * Determine if the given request has a valid signature for a relative URL.
     */
    public function hasValidRelativeSignature(RequestInterface $request, array $ignoreQuery = []): bool;

    /**
     * Determine if the signature from the given request matches the URL.
     */
    public function hasCorrectSignature(RequestInterface $request, bool $absolute = true, array $ignoreQuery = []): bool;

    /**
     * Determine if the expires timestamp from the given request is not from the past.
     */
    public function signatureHasNotExpired(RequestInterface $request): bool;

    /**
     * Get the full URL for the current request.
     */
    public function full(): string;

    /**
     * Get the current URL for the request.
     */
    public function current(): string;

    /**
     * Get the URL for the previous request.
     */
    public function previous(bool|string $fallback = false): string;

    /**
     * Get the previous path info for the request.
     *
     * @param mixed $fallback
     */
    public function previousPath($fallback = false): string;

    /**
     * Format the given URL segments into a single URL.
     */
    public function format(string $root, string $path): string;

    /**
     * Determine if the given path is a valid URL.
     */
    public function isValidUrl(string $path): bool;

    /**
     * Force the scheme for URLs.
     */
    public function forceScheme(?string $scheme): void;

    /**
     * Force the use of the HTTPS scheme for all generated URLs.
     */
    public function forceHttps(bool $force = true): void;

    /**
     * Set a callback to be used to format the host of generated URLs.
     */
    public function formatHostUsing(Closure $callback): static;

    /**
     * Set a callback to be used to format the path of generated URLs.
     */
    public function formatPathUsing(Closure $callback): static;

    /**
     * Set signed key for signing urls.
     */
    public function setSignedKey(?string $signedKey = null): static;
}
