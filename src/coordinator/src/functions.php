<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

/**
 * Block the current coroutine until the specified identifier is resumed.
 * Alias of `CoordinatorManager::until($identifier)->yield($timeout)`.
 */
function block(float $timeout = -1, string $identifier = Constants::WORKER_EXIT): bool
{
    return CoordinatorManager::until($identifier)->yield($timeout);
}

/**
 * Resume the coroutine that is blocked by the specified identifier.
 * Alias of `CoordinatorManager::until($identifier)->resume()`.
 */
function resume(string $identifier = Constants::WORKER_EXIT): void
{
    CoordinatorManager::until($identifier)->resume();
}

/**
 * Clear the coroutine that is blocked by the specified identifier.
 * Alias of `CoordinatorManager::clear($identifier)`.
 */
function clear(string $identifier = Constants::WORKER_EXIT): void
{
    CoordinatorManager::clear($identifier);
}
