<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Bus;

interface Dispatcher
{
    /**
     * Dispatch a command to its appropriate handler.
     */
    public function dispatch(mixed $command): mixed;

    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     */
    public function dispatchSync(mixed $command, mixed $handler = null): mixed;

    /**
     * Dispatch a command to its appropriate handler in the current process.
     */
    public function dispatchNow(mixed $command, mixed $handler = null): mixed;

    /**
     * Dispatch a command to its appropriate handler after the current process.
     */
    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void;

    /**
     * Determine if the given command has a handler.
     */
    public function hasCommandHandler(mixed $command): bool;

    /**
     * Retrieve the handler for a command.
     */
    public function getCommandHandler(mixed $command): mixed;

    /**
     * Set the pipes commands should be piped through before dispatching.
     */
    public function pipeThrough(array $pipes): static;

    /**
     * Map a command to a handler.
     */
    public function map(array $map): static;
}
