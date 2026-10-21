<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Support\Aws\SerializedCredentialProvider;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class SerializedCredentialProviderTest extends TestCase
{
    public function testEachCallerResolvesItsOwnCredentialsWhileHoldingTheProviderLock(): void
    {
        $active = 0;
        $maximumActive = 0;
        $provider = function (string $key) use (&$active, &$maximumActive): PromiseInterface {
            ++$active;
            $maximumActive = max($maximumActive, $active);
            $owner = Coroutine::id();
            $promise = new Promise(function () use (&$promise, &$active, $owner, $key): void {
                usleep(1000);
                $this->assertSame($owner, Coroutine::id());
                --$active;
                $promise->resolve(new Credentials($key, 'secret'));
            });

            return $promise;
        };

        $first = new SerializedCredentialProvider($provider);
        $second = new SerializedCredentialProvider($provider);
        $results = parallel([
            static fn (): string => $first('first')->wait()->getAccessKeyId(),
            static fn (): string => $second('second')->wait()->getAccessKeyId(),
        ]);

        $this->assertSame(['first', 'second'], $results);
        $this->assertSame(1, $maximumActive);
        $this->assertSame(0, $active);
    }

    public function testInvokableAndBoundMethodAliasesShareTheSameProviderLock(): void
    {
        $provider = new SerializedCredentialProviderTestProvider;
        $first = new SerializedCredentialProvider($provider);
        $second = new SerializedCredentialProvider([$provider, '__invoke']);
        $third = new SerializedCredentialProvider([$provider, 'resolve']);

        $results = parallel([
            static fn (): string => $first('first')->wait()->getAccessKeyId(),
            static fn (): string => $second('second')->wait()->getAccessKeyId(),
            static fn (): string => $third('third')->wait()->getAccessKeyId(),
        ]);

        $this->assertSame(['first', 'second', 'third'], $results);
        $this->assertSame(1, $provider->maximumActive);
    }

    #[DataProvider('staticCallableForms')]
    public function testNamedCallableAliasesShareTheSameProviderLock(callable $first, callable $second): void
    {
        SerializedCredentialProviderTestProvider::$shared = $provider = new SerializedCredentialProviderTestProvider;

        try {
            $first = new SerializedCredentialProvider($first);
            $second = new SerializedCredentialProvider($second);
            $results = parallel([
                static fn (): string => $first('first')->wait()->getAccessKeyId(),
                static fn (): string => $second('second')->wait()->getAccessKeyId(),
            ]);

            $this->assertSame(['first', 'second'], $results);
            $this->assertSame(1, $provider->maximumActive);
        } finally {
            SerializedCredentialProviderTestProvider::$shared = null;
        }
    }

    /**
     * Provide alternate spellings of named callable providers.
     */
    public static function staticCallableForms(): array
    {
        return [
            'static method' => [
                [SerializedCredentialProviderTestProvider::class, 'resolveShared'],
                '\\' . strtoupper(SerializedCredentialProviderTestProvider::class) . '::resolveShared',
            ],
            'function' => [
                __NAMESPACE__ . '\serializedCredentialProviderTestFunction',
                '\\' . strtoupper(__NAMESPACE__ . '\serializedCredentialProviderTestFunction'),
            ],
        ];
    }

    #[DataProvider('failureModes')]
    public function testFailureReleasesTheLockWithoutCachingTheFailure(bool $reject): void
    {
        $failure = new RuntimeException('Credentials unavailable.');
        $calls = 0;
        $provider = new SerializedCredentialProvider(function () use (&$calls, $failure, $reject): PromiseInterface {
            if (++$calls === 1) {
                return $reject ? Create::rejectionFor($failure) : throw $failure;
            }

            return Create::promiseFor(new Credentials('recovered', 'secret'));
        });

        $result = $provider();
        $this->assertSame(PromiseInterface::REJECTED, $result->getState());

        try {
            $result->wait();
            $this->fail('The credential failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame('recovered', $provider()->wait()->getAccessKeyId());
        $this->assertSame(2, $calls);
    }

    /**
     * Provide synchronous and asynchronous credential failures.
     */
    public static function failureModes(): array
    {
        return ['throws' => [false], 'rejects' => [true]];
    }

    public function testExplicitSdkMemoizationStillReusesCompletedCredentials(): void
    {
        $calls = 0;
        $credentials = new Credentials('cached', 'secret', null, time() + 3600);
        $memoized = CredentialProvider::memoize(function () use (&$calls, $credentials): PromiseInterface {
            ++$calls;
            $owner = Coroutine::id();
            $promise = new Promise(function () use (&$promise, $credentials, $owner): void {
                usleep(1000);
                $this->assertSame($owner, Coroutine::id());
                $promise->resolve($credentials);
            });

            return $promise;
        });

        $first = new SerializedCredentialProvider($memoized);
        $second = new SerializedCredentialProvider($memoized);
        $results = parallel([
            static fn (): mixed => $first()->wait(),
            static fn (): mixed => $second()->wait(),
        ]);

        $this->assertSame([$credentials, $credentials], $results);
        $this->assertSame(1, $calls);
    }

    public function testCancellationExceptionIsPropagatedUnchangedAndReleasesTheLock(): void
    {
        $cancellation = new CanceledException('Canceled credential resolution.');
        $calls = 0;
        $provider = new SerializedCredentialProvider(function () use (&$calls, $cancellation): PromiseInterface {
            if (++$calls === 1) {
                throw $cancellation;
            }

            return Create::promiseFor(new Credentials('next', 'secret'));
        });

        try {
            $provider();
            $this->fail('Cancellation was converted into an ordinary rejection.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame('next', $provider()->wait()->getAccessKeyId());
    }

    public function testTranslatedCancellationRemainsCancellationAndReleasesTheLock(): void
    {
        $started = new Channel(1);
        $blocked = new Channel(1);
        $finished = new Channel(1);
        $failure = new RuntimeException('The SDK translated an interrupted fetch.');
        $calls = 0;
        $provider = new SerializedCredentialProvider(function () use (&$calls, $started, $blocked, $failure): PromiseInterface {
            if (++$calls > 1) {
                return Create::promiseFor(new Credentials('next', 'secret'));
            }

            return new Promise(static function () use ($started, $blocked, $failure): never {
                $started->push(true);
                $blocked->pop();

                throw $failure;
            });
        });

        $owner = go(static function () use ($provider, $finished): void {
            try {
                $provider();
                $finished->push(null);
            } catch (CanceledException $exception) {
                $finished->push($exception);
            }
        });

        try {
            $this->assertTrue($started->pop(1));
            $this->assertTrue(Coroutine::cancelById($owner));
            $result = $finished->pop(1);
            $this->assertInstanceOf(CanceledException::class, $result);
            $this->assertSame($failure, $result->getPrevious());
            $this->assertSame('next', $provider()->wait()->getAccessKeyId());
        } finally {
            $blocked->close();
        }
    }

    public function testCancelingALockWaiterDoesNotReleaseTheOwnersLock(): void
    {
        $entered = new Channel(2);
        $release = new Channel(1);
        $finished = new Channel(3);
        $calls = 0;
        $provider = new SerializedCredentialProvider(function () use (&$calls, $entered, $release): PromiseInterface {
            ++$calls;
            $entered->push(true);
            $release->pop();

            return Create::promiseFor(new Credentials('done', 'secret'));
        });

        go(static function () use ($provider, $finished): void {
            $finished->push($provider()->wait());
        });

        try {
            $this->assertTrue($entered->pop(1));
            $waiter = go(static function () use ($provider, $finished): void {
                try {
                    $provider();
                } catch (CanceledException $exception) {
                    $finished->push($exception);
                }
            });

            $this->assertTrue(Coroutine::cancelById($waiter));
            $this->assertInstanceOf(CanceledException::class, $finished->pop(1));

            go(static function () use ($provider, $finished): void {
                $finished->push($provider()->wait());
            });

            $this->assertSame(1, $calls);
            $this->assertTrue($entered->isEmpty());
            $release->push(true);
            $this->assertInstanceOf(Credentials::class, $finished->pop(1));
            $this->assertTrue($entered->pop(1));
            $release->push(true);
            $this->assertInstanceOf(Credentials::class, $finished->pop(1));
            $this->assertSame(2, $calls);
        } finally {
            $release->close();
        }
    }
}

class SerializedCredentialProviderTestProvider
{
    public static ?self $shared = null;

    public int $maximumActive = 0;

    protected int $active = 0;

    /**
     * Invoke the named provider's shared state.
     */
    public static function resolveShared(string $key): PromiseInterface
    {
        return self::$shared->resolve($key);
    }

    /**
     * Resolve credentials after yielding to competing callers.
     */
    public function resolve(string $key): PromiseInterface
    {
        ++$this->active;
        $this->maximumActive = max($this->maximumActive, $this->active);
        $promise = new Promise(function () use (&$promise, $key): void {
            usleep(1000);
            --$this->active;
            $promise->resolve(new Credentials($key, 'secret'));
        });

        return $promise;
    }

    /**
     * Invoke the credential provider.
     */
    public function __invoke(string $key): PromiseInterface
    {
        return $this->resolve($key);
    }
}

/**
 * Invoke a named function provider with shared state.
 */
function serializedCredentialProviderTestFunction(string $key): PromiseInterface
{
    return SerializedCredentialProviderTestProvider::resolveShared($key);
}
