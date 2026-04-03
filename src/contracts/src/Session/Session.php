<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Session;

use SessionHandlerInterface;
use UnitEnum;

interface Session
{
    /**
     * Get the name of the session.
     */
    public function getName(): string;

    /**
     * Set the name of the session.
     */
    public function setName(string $name): void;

    /**
     * Get the current session ID.
     */
    public function getId(): ?string;

    /**
     * Set the session ID.
     */
    public function setId(?string $id): void;

    /**
     * Start the session, reading the data from a handler.
     */
    public function start(): bool;

    /**
     * Save the session data to storage.
     */
    public function save(): void;

    /**
     * Get all of the session data.
     */
    public function all(): array;

    /**
     * Checks if a key exists.
     */
    public function exists(array|UnitEnum|string $key): bool;

    /**
     * Checks if a key is present and not null.
     */
    public function has(array|UnitEnum|string $key): bool;

    /**
     * Get an item from the session.
     */
    public function get(UnitEnum|string $key, mixed $default = null): mixed;

    /**
     * Get the value of a given key and then forget it.
     */
    public function pull(UnitEnum|string $key, mixed $default = null): mixed;

    /**
     * Put a key / value pair or array of key / value pairs in the session.
     */
    public function put(array|UnitEnum|string $key, mixed $value = null): void;

    /**
     * Replace the given session attributes entirely.
     */
    public function replace(array $attributes): void;

    /**
     * Flash a key / value pair to the session.
     */
    public function flash(UnitEnum|string $key, mixed $value = true): void;

    /**
     * Flash an array of input to the session.
     */
    public function flashInput(array $value): void;

    /**
     * Reflash all of the session flash data.
     */
    public function reflash(): void;

    /**
     * Get a subset of the session data.
     */
    public function only(array $keys): array;

    /**
     * Determine if the flashed input contains an item.
     */
    public function hasOldInput(UnitEnum|string|null $key = null): bool;

    /**
     * Get the requested item from the flashed input array.
     */
    public function getOldInput(UnitEnum|string|null $key = null, mixed $default = null): mixed;

    /**
     * Get the CSRF token value.
     */
    public function token(): ?string;

    /**
     * Regenerate the CSRF token value.
     */
    public function regenerateToken(): void;

    /**
     * Remove an item from the session, returning its value.
     */
    public function remove(UnitEnum|string $key): mixed;

    /**
     * Remove one or many items from the session.
     */
    public function forget(array|UnitEnum|string $keys): void;

    /**
     * Remove all of the items from the session.
     */
    public function flush(): void;

    /**
     * Flush the session data and regenerate the ID.
     */
    public function invalidate(): bool;

    /**
     * Generate a new session identifier.
     */
    public function regenerate(bool $destroy = false): bool;

    /**
     * Generate a new session ID for the session.
     */
    public function migrate(bool $destroy = false): bool;

    /**
     * Determine if the session has been started.
     */
    public function isStarted(): bool;

    /**
     * Get the previous URL from the session.
     */
    public function previousUrl(): ?string;

    /**
     * Set the "previous" URL in the session.
     */
    public function setPreviousUrl(string $url): void;

    /**
     * Get the session handler instance.
     */
    public function getHandler(): SessionHandlerInterface;

    /**
     * Determine if the session handler needs a request.
     */
    public function handlerNeedsRequest(): bool;

    /**
     * Set the request on the handler instance.
     */
    public function setRequestOnHandler(\Hypervel\Http\Request $request): void;
}
