<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Routing;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;

interface UrlGenerator
{
    /**
     * Get the current URL for the request.
     */
    public function current(): string;

    /**
     * Get the URL for the previous request.
     */
    public function previous(bool|string $fallback = false): string;

    /**
     * Generate an absolute URL to the given path.
     */
    public function to(string $path, array|string $extra = [], ?bool $secure = null): string;

    /**
     * Generate a secure, absolute URL to the given path.
     */
    public function secure(string $path, array $parameters = []): string;

    /**
     * Generate the URL to an application asset.
     */
    public function asset(string $path, ?bool $secure = null): string;

    /**
     * Get the URL to a named route.
     *
     * @throws InvalidArgumentException
     */
    public function route(BackedEnum|string $name, array|string $parameters = [], bool $absolute = true): string;

    /**
     * Create a signed route URL for a named route.
     *
     * @throws InvalidArgumentException
     */
    public function signedRoute(BackedEnum|string $name, array|string $parameters = [], DateInterval|DateTimeInterface|int|null $expiration = null, bool $absolute = true): string;

    /**
     * Create a temporary signed route URL for a named route.
     */
    public function temporarySignedRoute(BackedEnum|string $name, DateInterval|DateTimeInterface|int $expiration, array $parameters = [], bool $absolute = true): string;

    /**
     * Generate an absolute URL with the given query parameters.
     */
    public function query(string $path, array $query = [], array|string $extra = [], ?bool $secure = null): string;

    /**
     * Get the URL to a controller action.
     */
    public function action(array|string $action, array|string $parameters = [], bool $absolute = true): string;

    /**
     * Get the root controller namespace.
     */
    public function getRootControllerNamespace(): ?string;

    /**
     * Set the root controller namespace.
     *
     * @return $this
     */
    public function setRootControllerNamespace(string $rootNamespace): static;
}
