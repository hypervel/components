<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Http\Exceptions\CoroutineOwnershipException;
use WeakMap;

/**
 * Track native ownership without retaining completed promises or idle transports.
 */
class CoroutineOwnership
{
    /** @var null|WeakMap<Promise, int> */
    protected static ?WeakMap $owners = null;

    /** @var null|WeakMap<Promise, PromiseInterface> */
    protected static ?WeakMap $adopted = null;

    /** @var null|WeakMap<Promise, CurlMultiHandler> */
    protected static ?WeakMap $transfers = null;

    /** @var null|WeakMap<CurlMultiHandler, TransportState> */
    protected static ?WeakMap $transports = null;

    /**
     * Record the coroutine that successfully constructed a mutable promise.
     */
    public static function record(Promise $promise): void
    {
        static::$owners ??= new WeakMap;
        static::$owners[$promise] = Coroutine::id();
    }

    /**
     * Require pending work to remain with its constructing coroutine.
     */
    public static function ensureOwner(Promise $promise, string $operation): void
    {
        $owner = static::$owners[$promise] ?? null;
        $current = Coroutine::id();

        if ($owner === null || $owner === $current) {
            return;
        }

        // Settlement and cancellation already ignore or reject settled promises;
        // only observation can still drive an adopted pending value.
        $completed = in_array($operation, ['then', 'wait', 'adopt'], true)
            ? static::completed($promise)
            : $promise->getState() !== PromiseInterface::PENDING;

        if (! $completed) {
            throw new CoroutineOwnershipException(
                "Coroutine [{$current}] cannot call [{$operation}] on a pending promise owned by coroutine [{$owner}]. "
                . 'Create and finish the operation in the same coroutine, then share its completed result.'
            );
        }
    }

    /**
     * Determine whether a promise and any adopted promise hold completed results.
     */
    public static function completed(PromiseInterface $promise): bool
    {
        if ($promise->getState() === PromiseInterface::PENDING) {
            return false;
        }

        $adopted = $promise instanceof Promise ? (static::$adopted[$promise] ?? null) : null;

        return $adopted === null || static::completed($adopted);
    }

    /**
     * Record a pending adoption only after Guzzle accepts the settlement.
     */
    public static function adopt(Promise $promise, PromiseInterface $value): void
    {
        static::$adopted ??= new WeakMap;
        static::$adopted[$promise] = $value;
    }

    /**
     * Refuse foreign access while a multi-handler has reserved or active transfers.
     */
    public static function ensureTransportOwner(CurlMultiHandler $handler, string $operation): void
    {
        $state = static::$transports[$handler] ?? null;
        $current = Coroutine::id();

        if ($state !== null && $state->owner !== $current) {
            throw new CoroutineOwnershipException(
                "Coroutine [{$current}] cannot call [{$operation}] on a cURL multi-handler with active work owned by coroutine [{$state->owner}]. "
                . 'Create and finish each operation in its owning coroutine.'
            );
        }
    }

    /**
     * Reserve ownership before request preparation can yield.
     */
    public static function reserve(CurlMultiHandler $handler): void
    {
        static::ensureTransportOwner($handler, '__invoke');
        static::$transports ??= new WeakMap;
        $state = static::$transports[$handler] ??= new TransportState(Coroutine::id());
        ++$state->active;
    }

    /**
     * Retain a pending native transfer until settlement or owner exit.
     */
    public static function track(Promise $promise, CurlMultiHandler $handler): void
    {
        static::$transfers ??= new WeakMap;
        static::$transfers[$promise] = $handler;
        CoroutineState::forCurrent()->add($promise);
    }

    /**
     * Release accounting when a native transfer settles or is canceled.
     */
    public static function settled(Promise $promise): void
    {
        $handler = static::$transfers[$promise] ?? null;

        if ($handler === null) {
            return;
        }

        unset(static::$transfers[$promise]);
        CoroutineState::current()?->forget($promise);
        static::release($handler);
    }

    /**
     * Release one reservation or completed transfer.
     */
    public static function release(CurlMultiHandler $handler): void
    {
        if (--static::$transports[$handler]->active === 0) {
            unset(static::$transports[$handler]);
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$owners = null;
        static::$adopted = null;
        static::$transfers = null;
        static::$transports = null;
    }
}
