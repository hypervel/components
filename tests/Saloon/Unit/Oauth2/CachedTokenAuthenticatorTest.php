<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Oauth2;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\CachedTokenAuthenticator;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\ClientCredentialsConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class CachedTokenAuthenticatorTest extends TestCase
{
    /**
     * Register Saloon for the acquisition tests.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Configure serialized token caching and a single-slot database pool.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('cache.serializable_classes', [CacheableCustomToken::class]);
        $app->make('config')->set('cache.stores.oauth-file', [
            'driver' => 'file',
            'path' => $app->storagePath('framework/cache/oauth-tokens'),
        ]);
        $app->make('config')->set('database.connections.oauth-pool', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'pool' => ['testing_enabled' => true, 'max_connections' => 1, 'wait_timeout' => 1.0],
        ]);
    }

    /**
     * Clear cached test credentials.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('oauth-file')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testSerializedTokensRetainExpiryAndCustomAuthentication(): void
    {
        Date::setTestNow('2026-01-01 00:00:00');
        $first = new CachedTokenAuthenticator('serialized', fn (): OAuthAuthenticator => new CacheableCustomToken(
            'access',
            expiresAt: Date::now()->addHour()->toDateTimeImmutable(),
        ), 'oauth-file');
        $this->assertSame('Bearer custom:access', $this->authorization($first));

        $second = new CachedTokenAuthenticator('serialized', fn (): never => $this->fail('The stored token must be reused.'), 'oauth-file');
        $this->assertSame('Bearer custom:access', $this->authorization($second));
    }

    public function testSeparateInstancesShareAnAcquisitionAndCompletedToken(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls): OAuthAuthenticator {
            ++$calls;
            usleep(5000);

            return new AccessTokenAuthenticator('shared');
        };
        $first = new CachedTokenAuthenticator('grant', $resolve, 'worker-array');
        $second = new CachedTokenAuthenticator('grant', $resolve, 'worker-array');

        $results = parallel([
            fn (): string => $this->authorization($first),
            fn (): string => $this->authorization($second),
        ]);

        $this->assertSame(['Bearer shared', 'Bearer shared'], $results);
        $this->assertSame('Bearer shared', $this->authorization(new CachedTokenAuthenticator('grant', $resolve, 'worker-array')));
        $this->assertSame(1, $calls);
    }

    public function testFailureIsSharedWithWaitersButNotRetainedForLaterCalls(): void
    {
        $calls = 0;
        $failure = new RuntimeException('Provider unavailable.');
        $resolve = function () use (&$calls, $failure): OAuthAuthenticator {
            ++$calls;
            usleep(5000);
            throw $failure;
        };
        $attempt = function () use ($resolve): RuntimeException {
            try {
                $this->authorization(new CachedTokenAuthenticator('failed', $resolve, 'worker-array'));
            } catch (RuntimeException $exception) {
                return $exception;
            }

            $this->fail('The provider failure was not propagated.');
        };

        $this->assertSame([$failure, $failure], parallel([$attempt, $attempt]));
        $this->assertSame(1, $calls);
        $this->assertSame($failure, $attempt());
        $this->assertSame(2, $calls);
    }

    public function testAcquisitionAndItsWaitersReleaseIdleDatabaseConnections(): void
    {
        $pool = $this->app->make(PoolManager::class)->pool('oauth-pool');
        $release = new Channel(1);
        $results = new Channel(2);
        $enteredProvider = new Channel(1);
        $enteredWaiter = new Channel(1);
        $idleAtProvider = null;
        $resolve = function () use ($pool, $release, $enteredProvider, &$idleAtProvider): OAuthAuthenticator {
            $idleAtProvider = $pool->getIdleCount();
            $enteredProvider->push(true);
            $release->pop(2);

            return new AccessTokenAuthenticator('shared');
        };
        $call = function (bool $waiter = false) use ($resolve, $results, $enteredWaiter): void {
            try {
                DB::connection('oauth-pool')->selectOne('select 1');
                if ($waiter) {
                    $enteredWaiter->push(true);
                }
                $results->push($this->authorization(new CachedTokenAuthenticator('pool-test', $resolve, 'worker-array')));
            } catch (Throwable $exception) {
                $results->push($exception);
            }
        };
        $owner = go($call);

        try {
            $this->assertTrue($enteredProvider->pop(2));
            $waiter = go(fn () => $call(true));
            $this->assertTrue($enteredWaiter->pop(2));
            $this->assertSame(1, $idleAtProvider);
            $this->assertTrue($results->isEmpty());
            $this->assertSame(7, DB::connection('oauth-pool')->selectOne('select 7 as value')->value);
            $release->push(true);
            $this->assertSame('Bearer shared', $results->pop(2));
            $this->assertSame('Bearer shared', $results->pop(2));
        } finally {
            $release->close();
            EngineCoroutine::join([$owner, ...isset($waiter) ? [$waiter] : []], 2);
            $results->close();
            $enteredProvider->close();
            $enteredWaiter->close();
        }
    }

    public function testShortTokenLifetimeUsesOneFixedDeadlineAndForgetClearsBothLevels(): void
    {
        Date::setTestNow('2026-01-01 00:00:00');
        $calls = 0;
        $resolve = function () use (&$calls): OAuthAuthenticator {
            return new AccessTokenAuthenticator('token-' . ++$calls, expiresAt: Date::now()->addSeconds(10)->toDateTimeImmutable());
        };
        $first = new CachedTokenAuthenticator('short', $resolve, 'worker-array');
        $this->assertSame('Bearer token-1', $this->authorization($first));

        Date::setTestNow('2026-01-01 00:00:04');
        $second = new CachedTokenAuthenticator('short', $resolve, 'worker-array');
        $this->assertSame('Bearer token-1', $this->authorization($second));

        Date::setTestNow('2026-01-01 00:00:05');
        $this->assertSame('Bearer token-2', $this->authorization($second));
        $this->assertSame('Bearer token-2', $this->authorization($first));
        $first->forget();
        $this->assertSame('Bearer token-3', $this->authorization($first));
        $this->assertSame('Bearer token-2', $this->authorization($second));
    }

    public function testAnExpiredAcquisitionIsRejectedWithoutRefetching(): void
    {
        Date::setTestNow('2026-01-01 00:00:00');
        $calls = 0;
        $authenticator = new CachedTokenAuthenticator('expired', function () use (&$calls): OAuthAuthenticator {
            ++$calls;

            return new AccessTokenAuthenticator('expired', expiresAt: Date::now()->toDateTimeImmutable());
        });

        $this->expectException(UnexpectedValueException::class);

        try {
            $this->authorization($authenticator);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testConvenienceSeparatesGrantsAndKeepsRequestModifiers(): void
    {
        $mock = Saloon::fake([
            MockResponse::make(['access_token' => 'first', 'expires_in' => 3600]),
            MockResponse::make(['access_token' => 'secret']),
            MockResponse::make(['access_token' => 'scope']),
            MockResponse::make(['access_token' => 'endpoint']),
            MockResponse::make(['access_token' => 'audience']),
        ]);
        $connector = new ClientCredentialsConnector;
        $this->assertSame('Bearer first', $this->authorization($connector->cachedAccessToken(store: 'oauth-file')));
        $this->assertSame('Bearer first', $this->authorization($connector->cachedAccessToken(store: 'oauth-file')));
        $this->assertSame('Bearer secret', $this->authorization((new ClientCredentialsConnector(['clientSecret' => 'other']))->cachedAccessToken(store: 'oauth-file')));
        $this->assertSame('Bearer scope', $this->authorization($connector->cachedAccessToken(scopes: ['read'], store: 'oauth-file')));
        $this->assertSame('Bearer endpoint', $this->authorization((new ClientCredentialsConnector(['tokenEndpoint' => '/other']))->cachedAccessToken(store: 'oauth-file')));
        $this->assertSame('Bearer audience', $this->authorization($connector->cachedAccessToken(
            store: 'oauth-file',
            requestModifier: fn (Request $request) => $request->withData(['audience' => 'billing']),
            key: 'billing',
        )));
        $this->assertSame('billing', $mock->lastPendingRequest()->body()['audience']);
        $mock->assertSentCount(5);
    }

    /**
     * Read the authentication applied to a real pending request.
     */
    protected function authorization(CachedTokenAuthenticator $authenticator): string
    {
        return (new TestConnector)->createPendingRequest(UserRequest::make()->authenticate($authenticator))->headers()['Authorization'];
    }
}

class CacheableCustomToken extends AccessTokenAuthenticator
{
    /**
     * Apply the custom token spelling through the inherited authentication method.
     */
    public function getAccessToken(): string
    {
        return 'custom:' . $this->accessToken;
    }
}
