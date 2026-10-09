<?php

declare(strict_types=1);

namespace Hypervel\Support\Aws;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Coroutine\Locker;
use Hypervel\Engine\Coroutine;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Keep a shared credential provider's pending work in the coroutine invoking it.
 */
class SerializedCredentialProvider
{
    protected readonly Closure $provider;

    protected readonly string $lockKey;

    /** @var array<string, int> Lock key => owning coroutine ID */
    protected static array $owners = [];

    /**
     * Create a serialized credential provider.
     */
    public function __construct(callable $provider)
    {
        $this->provider = $provider(...);
        $this->lockKey = 'aws-credential-provider.' . $this->identity($provider);
    }

    /**
     * Identify the state behind a callable: one lock per bound object, whichever method is called on it.
     */
    protected function identity(callable $provider): string
    {
        if (is_string($provider) && str_contains($provider, '::')) {
            $provider = explode('::', $provider, 2);
        }

        return match (true) {
            is_object($provider) => 'object:' . spl_object_id($provider),
            is_array($provider) && is_object($provider[0]) => 'object:' . spl_object_id($provider[0]),
            is_array($provider) => 'static:' . strtolower(ltrim($provider[0], '\\')),
            default => 'function:' . strtolower(ltrim($provider, '\\')),
        };
    }

    /**
     * Resolve this caller's credentials before releasing the shared provider.
     */
    public function __invoke(mixed ...$arguments): PromiseInterface
    {
        // Waiting drains Guzzle callbacks, which may request credentials again.
        $coroutine = Coroutine::id();
        $acquiresLock = (static::$owners[$this->lockKey] ?? null) !== $coroutine;

        if ($acquiresLock) {
            while (! Locker::lock($this->lockKey)) {
                // The previous caller finished; compete to run the provider next.
            }

            static::$owners[$this->lockKey] = $coroutine;
        }

        try {
            return Create::promiseFor(($this->provider)(...$arguments)->wait());
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // SDK providers can wrap an interrupted fetch in CredentialsException.
            if (Coroutine::isCanceled()) {
                throw new CanceledException('Resolving AWS credentials was canceled.', previous: $exception);
            }

            return Create::rejectionFor($exception);
        } finally {
            if ($acquiresLock) {
                unset(static::$owners[$this->lockKey]);
                Locker::unlock($this->lockKey);
            }
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$owners = [];
    }
}
