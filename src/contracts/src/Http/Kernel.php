<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Support\Attributes\BootOnly;
use Symfony\Component\HttpFoundation\Response;

interface Kernel
{
    /**
     * Bootstrap the application for HTTP requests.
     */
    public function bootstrap(): void;

    /**
     * Handle an incoming HTTP request.
     */
    public function handle(Request $request): Response;

    /**
     * Perform any final actions for the request lifecycle.
     */
    public function terminate(Request $request, Response $response): void;

    /**
     * Determine if the kernel has a given middleware.
     */
    public function hasMiddleware(string $middleware): bool;

    /**
     * Add a new middleware to the beginning of the stack if it does not already exist.
     *
     * Middleware persists in the kernel's global stack for the worker lifetime
     * and runs on every subsequent request across all coroutines.
     *
     * @return $this
     */
    #[BootOnly(whenShared: true)]
    public function prependMiddleware(string $middleware): static;

    /**
     * Add a new middleware to the end of the stack if it does not already exist.
     *
     * Middleware persists in the kernel's global stack for the worker lifetime
     * and runs on every subsequent request across all coroutines.
     *
     * @return $this
     */
    #[BootOnly(whenShared: true)]
    public function pushMiddleware(string $middleware): static;

    /**
     * Prepend the given middleware to the given middleware group.
     *
     * The middleware group persists in the kernel for the worker lifetime
     * and runs on every subsequent request matching the group.
     *
     * @return $this
     */
    #[BootOnly]
    public function prependMiddlewareToGroup(string $group, string $middleware): static;

    /**
     * Append the given middleware to the given middleware group.
     *
     * The middleware group persists in the kernel for the worker lifetime
     * and runs on every subsequent request matching the group.
     *
     * @return $this
     */
    #[BootOnly]
    public function appendMiddlewareToGroup(string $group, string $middleware): static;

    /**
     * Prepend the given middleware to the middleware priority list.
     *
     * The middleware priority list persists in the kernel for the worker lifetime
     * and affects middleware ordering for every subsequent request.
     *
     * @return $this
     */
    #[BootOnly]
    public function prependToMiddlewarePriority(string $middleware): static;

    /**
     * Append the given middleware to the middleware priority list.
     *
     * The middleware priority list persists in the kernel for the worker lifetime
     * and affects middleware ordering for every subsequent request.
     *
     * @return $this
     */
    #[BootOnly]
    public function appendToMiddlewarePriority(string $middleware): static;

    /**
     * Add the given middleware to the middleware priority list before other middleware.
     *
     * The middleware priority list persists in the kernel for the worker lifetime
     * and affects middleware ordering for every subsequent request.
     *
     * @param array<int, string>|string $before
     * @return $this
     */
    #[BootOnly]
    public function addToMiddlewarePriorityBefore(string|array $before, string $middleware): static;

    /**
     * Add the given middleware to the middleware priority list after other middleware.
     *
     * The middleware priority list persists in the kernel for the worker lifetime
     * and affects middleware ordering for every subsequent request.
     *
     * @param array<int, string>|string $after
     * @return $this
     */
    #[BootOnly]
    public function addToMiddlewarePriorityAfter(string|array $after, string $middleware): static;

    /**
     * Get the priority-sorted list of middleware.
     *
     * @return string[]
     */
    public function getMiddlewarePriority(): array;

    /**
     * Get the application's global middleware.
     *
     * @return array<int, class-string|string>
     */
    public function getGlobalMiddleware(): array;

    /**
     * Set the application's global middleware.
     *
     * Middleware persists in the kernel's global stack for the worker lifetime
     * and runs on every subsequent request across all coroutines.
     *
     * @param array<int, class-string|string> $middleware
     * @return $this
     */
    #[BootOnly]
    public function setGlobalMiddleware(array $middleware): static;

    /**
     * Get the application's route middleware groups.
     *
     * @return array<string, array<int, class-string|string>>
     */
    public function getMiddlewareGroups(): array;

    /**
     * Set the application's middleware groups.
     *
     * Middleware groups persist in the kernel for the worker lifetime and affect
     * every subsequent request matching those groups.
     *
     * @param array<string, array<int, class-string|string>> $groups
     * @return $this
     */
    #[BootOnly]
    public function setMiddlewareGroups(array $groups): static;

    /**
     * Get the application's route middleware aliases.
     *
     * @return array<string, class-string|string>
     */
    public function getMiddlewareAliases(): array;

    /**
     * Set the application's route middleware aliases.
     *
     * Middleware aliases persist in the kernel for the worker lifetime and affect
     * every subsequent request that uses them.
     *
     * @param array<string, class-string|string> $aliases
     * @return $this
     */
    #[BootOnly]
    public function setMiddlewareAliases(array $aliases): static;

    /**
     * Set the application's middleware priority.
     *
     * The middleware priority list persists in the kernel for the worker lifetime
     * and affects middleware ordering for every subsequent request.
     *
     * @param string[] $priority
     * @return $this
     */
    #[BootOnly]
    public function setMiddlewarePriority(array $priority): static;

    /**
     * Get the application instance.
     */
    public function getApplication(): Application;
}
