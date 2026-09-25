<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Aws\Credentials\CredentialsInterface;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository;
use Swoole\Coroutine\CanceledException;
use Throwable;

class AwsCredentialCache
{
    /**
     * The number of seconds before expiration that credentials should be refreshed.
     */
    protected const int REFRESH_WINDOW = 60;

    /**
     * The number of seconds the credential refresh lock should be maintained.
     */
    protected const int LOCK_SECONDS = 15;

    /**
     * The number of seconds to wait for another process to refresh credentials.
     */
    protected const int LOCK_WAIT_SECONDS = 5;

    /**
     * The resolver for the backing cache repository.
     *
     * @var Closure(): Repository
     */
    protected Closure $repository;

    /**
     * The resolver for the fallback cache repository, if any.
     *
     * @var null|(Closure(): Repository)
     */
    protected ?Closure $fallback;

    /**
     * Create a new AWS credential cache.
     *
     * @param Closure(): Repository $repository
     * @param null|(Closure(): Repository) $fallback
     */
    public function __construct(Closure $repository, ?Closure $fallback = null)
    {
        $this->repository = $repository;
        $this->fallback = $fallback;
    }

    /**
     * Resolve credentials while sharing a single refresh across processes.
     */
    public function resolve(string $key, callable $provider): PromiseInterface
    {
        if ($credentials = $this->freshCredentials($key)) {
            return Create::promiseFor($credentials);
        }

        foreach ($this->repositories() as $repository) {
            $lock = null;

            try {
                $store = $repository()->getStore();

                if (! $store instanceof LockProvider) {
                    continue;
                }

                $lock = $store->lock($key . ':refresh', static::LOCK_SECONDS);

                $lock->block(static::LOCK_WAIT_SECONDS);
            } catch (CanceledException $e) {
                // The lock may have been acquired before the wait was canceled;
                // releasing an unowned lock is a no-op.
                $this->releaseAfterFailure($lock, $e);
            } catch (Throwable) {
                continue;
            }

            // Only a cancellation escapes the recheck, and the held lock must not outlive it.
            try {
                $credentials = $this->freshCredentials($key);
            } catch (Throwable $e) {
                $this->releaseAfterFailure($lock, $e);
            }

            if ($credentials) {
                $this->release($lock);

                return Create::promiseFor($credentials);
            }

            return $this->resolveAndCache($key, $provider, $lock);
        }

        return $this->resolveAndCache($key, $provider);
    }

    /**
     * Get fresh credentials from the available cache repositories.
     */
    protected function freshCredentials(string $key): ?CredentialsInterface
    {
        foreach ($this->repositories() as $repository) {
            try {
                $credentials = $repository()->get($key);

                if ($credentials instanceof CredentialsInterface
                    && ! is_null($credentials->getExpiration())
                    && $credentials->getExpiration() - time() > static::REFRESH_WINDOW) {
                    return $credentials;
                }
            } catch (CanceledException $e) {
                throw $e;
            } catch (Throwable) {
                // An unavailable store falls back to the next store or a direct fetch.
            }
        }

        return null;
    }

    /**
     * Resolve and cache credentials before releasing the refresh lock.
     */
    protected function resolveAndCache(string $key, callable $provider, ?Lock $lock = null): PromiseInterface
    {
        try {
            $promise = $provider();
        } catch (Throwable $e) {
            $this->releaseAfterFailure($lock, $e);
        }

        return $promise->then(
            function (CredentialsInterface $credentials) use ($key, $lock): CredentialsInterface {
                try {
                    $expiration = $credentials->getExpiration();

                    // Credentials without an expiration have no safe lifetime in a shared,
                    // persistent store, where keys rotated in place would never be picked up.
                    if (! is_null($expiration)
                        && ($ttl = $expiration - time() - static::REFRESH_WINDOW) > 0) {
                        $this->put($key, $credentials, $ttl);
                    } else {
                        $this->forget($key);
                    }
                } catch (Throwable $e) {
                    $this->releaseAfterFailure($lock, $e);
                }

                $this->release($lock);

                return $credentials;
            },
            function (mixed $reason) use ($lock): RejectedPromise {
                // Throwing a Throwable reason keeps the promise rejected with that same reason.
                if ($reason instanceof Throwable) {
                    $this->releaseAfterFailure($lock, $reason);
                }

                $this->release($lock);

                return new RejectedPromise($reason);
            },
        );
    }

    /**
     * Store the credentials, silently ignoring unavailable cache stores.
     */
    protected function put(string $key, CredentialsInterface $credentials, int $ttl): void
    {
        foreach ($this->repositories() as $repository) {
            try {
                $repository()->put($key, $credentials, $ttl);
            } catch (CanceledException $e) {
                throw $e;
            } catch (Throwable) {
                // An unavailable store must not prevent credential resolution.
            }
        }
    }

    /**
     * Remove the cached credentials, silently ignoring unavailable cache stores.
     */
    protected function forget(string $key): void
    {
        foreach ($this->repositories() as $repository) {
            try {
                $repository()->forget($key);
            } catch (CanceledException $e) {
                throw $e;
            } catch (Throwable) {
                // An unavailable store must not prevent credential resolution.
            }
        }
    }

    /**
     * Get the cache repository resolvers in order of preference.
     *
     * @return array<int, Closure(): Repository>
     */
    protected function repositories(): array
    {
        return array_filter([$this->repository, $this->fallback]);
    }

    /**
     * Release the credential refresh lock without affecting credential resolution.
     */
    protected function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (CanceledException $e) {
            throw $e;
        } catch (Throwable) {
            // The lock expires on its own if it cannot be released.
        }
    }

    /**
     * Release the credential refresh lock while preserving the primary failure.
     *
     * A cancellation during cleanup replaces an ordinary failure, but never an
     * original cancellation.
     */
    protected function releaseAfterFailure(?Lock $lock, Throwable $failure): never
    {
        try {
            $lock?->release();
        } catch (CanceledException $cleanupCancellation) {
            if (! $failure instanceof CanceledException) {
                throw $cleanupCancellation;
            }
        } catch (Throwable) {
            // Preserve the primary failure.
        }

        throw $failure;
    }
}
