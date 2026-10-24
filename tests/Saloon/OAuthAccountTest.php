<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\OAuthAccountTest;

use DateTimeImmutable;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Exceptions\OAuthAccountReconnectRequiredException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\OAuthAccountAuthenticator;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\OAuthAccount;
use Hypervel\Saloon\OAuthAccounts;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\HasOAuthAccounts;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\OAuth2Connector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Mocking\UnseekableBodyMockResponse;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class OAuthAccountTest extends TestCase
{
    protected ?string $migration = null;

    protected bool $credentialCacheEnabled = true;

    /**
     * Register Saloon for the account tests.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Configure isolated databases and a shared credential cache.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'database.default' => 'oauth',
            'database.connections.oauth' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'pool' => ['testing_enabled' => true, 'max_connections' => 1, 'wait_timeout' => 1.0],
            ],
            'database.connections.oauth-secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'cache.stores.oauth-accounts' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/oauth-accounts'),
            ],
            'saloon.oauth_accounts.cache' => [
                'enabled' => $this->credentialCacheEnabled,
                'store' => 'oauth-accounts',
            ],
        ]);
    }

    /**
     * Exercise uncached account reads.
     */
    protected function disableCredentialCache(ApplicationContract $app): void
    {
        $this->credentialCacheEnabled = false;
    }

    /**
     * Select a custom model after reading the current default.
     */
    protected function configureCustomAccountModel(): void
    {
        OAuthAccounts::accountModel();
        OAuthAccounts::useAccountModel(CustomAccount::class);
    }

    /**
     * Select a model with guarded credential writes.
     */
    protected function configureGuardedAccountModel(): void
    {
        OAuthAccounts::useAccountModel(GuardedAccount::class);
    }

    /**
     * Select a model whose claim cleanup fails.
     */
    protected function configureCleanupFailingAccountModel(): void
    {
        OAuthAccounts::useAccountModel(CleanupFailingAccount::class);
    }

    /**
     * Create the schema through the public migration command.
     */
    protected function setUpInCoroutine(): void
    {
        Date::setTestNow('2026-01-01 00:00:00');
        $this->artisan('make:oauth-accounts-table')->assertSuccessful();
        $files = $this->app->make('files')->glob($this->app->databasePath('migrations/*_create_oauth_accounts_table.php'));
        $this->migration = $files[0];
        (require $this->migration)->up();
        Schema::create('owners', function (Blueprint $table): void {
            $table->id();
        });
    }

    /**
     * Remove the generated migration and cached credentials.
     */
    protected function tearDown(): void
    {
        try {
            if ($this->migration !== null) {
                $this->app->make('files')->delete($this->migration);
            }

            $this->app->make('cache')->store('oauth-accounts')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testRelationPersistsEncryptedCredentialsAndCreatesFixedRenewalDeadline(): void
    {
        $owner = Owner::create();
        $account = $owner->oauthAccounts()->create([
            'provider' => 'example',
            'provider_account_id' => 'external-id',
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
            'expires_at' => Date::now()->addSeconds(10),
            'scopes' => [],
        ]);

        $stored = DB::table('oauth_accounts')->first();
        $this->assertNotSame('secret-access', $stored->access_token);
        $this->assertNotSame('secret-refresh', $stored->refresh_token);
        $this->assertStringNotContainsString('secret-', json_encode($account));
        $this->assertSame('secret-access', $account->fresh()->newOAuthAuthenticator()->getAccessToken());
        $this->assertSame([], $account->fresh()->scopes);
        $accounts = $this->app->make(OAuthAccounts::class);
        $serialized = serialize($accounts->find($accounts->identity($account)));
        $this->assertStringNotContainsString('secret-access', $serialized);
        $this->assertStringNotContainsString('secret-refresh', $serialized);
        $this->assertSame('2026-01-01 00:00:05', $account->refresh_at->format('Y-m-d H:i:s'));
        $this->assertTrue($account->owner->is($owner));

        $revision = $account->credential_revision;
        Date::setTestNow('2026-01-01 00:00:04');
        $account->update(['provider_account_id' => 'renamed']);
        $this->assertSame($revision, $account->credential_revision);
        $this->assertSame('2026-01-01 00:00:05', $account->refresh_at->format('Y-m-d H:i:s'));

        $account->update(['access_token' => 'replacement', 'expires_at' => Date::now()->addHour()]);
        $this->assertNotSame($revision, $account->credential_revision);
        $this->assertSame('2026-01-01 00:59:34', $account->refresh_at->format('Y-m-d H:i:s'));
        $account->update(['expires_at' => null]);
        $this->assertNull($account->refresh_at);
    }

    public function testWarmCredentialsRequireNoQueriesAndSaveAndDeleteInvalidateThem(): void
    {
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'first']);
        $accounts = $this->app->make(OAuthAccounts::class);
        $identity = $accounts->identity($account);
        $accounts->find($identity);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('first', $accounts->find($identity)->newOAuthAuthenticator()->getAccessToken());
        $this->assertSame([], DB::getQueryLog());

        $account->update(['access_token' => 'second']);
        $this->assertSame('second', $accounts->find($identity)->access_token);
        $deleting = false;
        OAuthAccount::deleting(static function () use (&$deleting): void {
            $deleting = true;
        });
        $account->delete();
        $this->assertTrue($deleting);
        $this->expectException(ModelNotFoundException::class);
        $accounts->find($identity);
    }

    public function testWriteAliasesShareCredentialInvalidation(): void
    {
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'old']);
        $accounts = $this->app->make(OAuthAccounts::class);
        $identity = $accounts->identity($account);
        $authenticator = $account->authenticator(fn (): never => $this->fail('Non-expiring credentials must not refresh.'));
        $this->assertSame('Bearer old', $this->authorization($authenticator));

        $write = $account->fresh()->setConnection('oauth::write');
        $writeIdentity = $accounts->identity($write);
        $this->assertSame($identity->key, $writeIdentity->key);
        $this->assertSame('oauth::write', $writeIdentity->connection);
        $write->update(['access_token' => 'new']);
        $this->assertSame('Bearer new', $this->authorization($authenticator));

        $write->delete();
        $this->expectException(ModelNotFoundException::class);
        $this->authorization($authenticator);
    }

    public function testNonUtcDatesPreserveExpiryAndRenewalInstants(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            Date::setTestNow('2026-01-01 00:00:00');
            $account = Owner::create()->oauthAccounts()->create([
                'provider' => 'example', 'access_token' => 'old', 'refresh_token' => 'refresh',
                'expires_at' => Date::now()->addHour(),
            ]);
            $this->assertSame(Date::now()->addHour()->getTimestamp() - 30, $account->fresh()->refresh_at->getTimestamp());
            Date::setTestNow('2026-01-01 00:59:30');
            $expiry = new DateTimeImmutable('2026-01-01 07:00:00 UTC');
            $authenticator = $account->authenticator(fn (): OAuthAuthenticator => new AccessTokenAuthenticator('renewed', expiresAt: $expiry));

            $this->assertSame('Bearer renewed', $this->authorization($authenticator));
            $persisted = $account->fresh();
            $this->assertSame($expiry->getTimestamp(), $persisted->expires_at->getTimestamp());
            $this->assertSame($expiry->getTimestamp() - 30, $persisted->refresh_at->getTimestamp());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    #[DefineEnvironment('disableCredentialCache')]
    public function testCachingCanBeDisabledWithoutChangingAuthentication(): void
    {
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'uncached']);
        $authenticator = $account->authenticator(fn (): never => $this->fail('Non-expiring credentials must not refresh.'));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('Bearer uncached', $this->authorization($authenticator));
        $this->assertSame('Bearer uncached', $this->authorization($authenticator));
        $this->assertCount(2, DB::getQueryLog());
    }

    #[DefineEnvironment('configureCustomAccountModel')]
    public function testCustomModelTableAndTokenFactorySurviveSharedCaching(): void
    {
        Schema::rename('oauth_accounts', 'custom_oauth_accounts');
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'stored']);
        $this->assertInstanceOf(CustomAccount::class, $account);
        $authenticator = $account->authenticator(fn (): never => $this->fail('Non-expiring credentials must not refresh.'));

        $this->assertSame('Bearer custom:stored', $this->authorization($authenticator));
        $this->assertSame('Bearer custom:stored', $this->authorization($authenticator));
    }

    public function testCapturedConnectionsKeepIdenticalAccountIdsSeparate(): void
    {
        DB::usingConnection('oauth-secondary', fn () => (require $this->migration)->up());
        $first = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'first']);
        $second = new OAuthAccount;
        $second->setConnection('oauth-secondary')->forceFill([
            'id' => $first->getKey(), 'owner_type' => Owner::class, 'owner_id' => 1,
            'provider' => 'example', 'access_token' => 'second',
        ])->save();
        $refresh = fn (): never => $this->fail('Non-expiring tokens must not refresh.');
        $firstAuthenticator = $first->authenticator($refresh);
        $secondAuthenticator = $second->authenticator($refresh);

        $this->assertSame('Bearer first', $this->authorization($firstAuthenticator));
        $this->assertSame('Bearer second', $this->authorization($secondAuthenticator));
        $this->assertSame('Bearer first', DB::usingConnection('oauth-secondary', fn (): string => $this->authorization($firstAuthenticator)));
    }

    #[DefineEnvironment('configureGuardedAccountModel')]
    public function testPersistenceFailureRespectsTheCustomBuilderAndDoesNotRepeatTheProviderCall(): void
    {
        $account = $this->refreshableAccount();
        $calls = 0;
        $authenticator = $account->authenticator(function () use (&$calls): OAuthAuthenticator {
            ++$calls;

            return new AccessTokenAuthenticator('renewed');
        });

        try {
            $this->authorization($authenticator);
            $this->fail('The model builder must control conditional credential writes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Credential writes are denied.', $exception->getMessage());
        }

        $this->assertSame(1, $calls);
        $this->assertSame('old', $account->fresh()->access_token);
        $this->assertNull($account->fresh()->refresh_claim);
    }

    public function testAnExplicitEmptyScopeSetReplacesStoredScopes(): void
    {
        $account = $this->refreshableAccount();
        $authenticator = $account->authenticator(fn (): OAuthAuthenticator => new AccessTokenAuthenticator('renewed', scopes: []));

        $this->assertSame('Bearer renewed', $this->authorization($authenticator));
        $this->assertSame([], $account->fresh()->scopes);
    }

    public function testRollbackDoesNotPublishOrInvalidateUncommittedCredentials(): void
    {
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'committed']);
        $accounts = $this->app->make(OAuthAccounts::class);
        $identity = $accounts->identity($account);
        $accounts->find($identity);

        DB::beginTransaction();
        try {
            $account->update(['access_token' => 'uncommitted']);
            $this->assertSame('uncommitted', $accounts->find($identity)->access_token);
        } finally {
            DB::rollBack();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame('committed', $accounts->find($identity)->access_token);
        $this->assertSame([], DB::getQueryLog());
    }

    public function testCommittedSaveInvalidatesItsCapturedKeyAfterTheOuterContextIsRestored(): void
    {
        Schema::table('oauth_accounts', fn (Blueprint $table) => $table->string('partition_id')->nullable());
        $current = 'first';
        OAuthAccounts::resolvePartitionUsing('partition_id', static function () use (&$current): ?string {
            return $current;
        });
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'old']);
        $accounts = $this->app->make(OAuthAccounts::class);
        $identity = $accounts->identity($account);
        $this->assertSame('old', $accounts->find($identity)->access_token);

        DB::transaction(function () use ($account, &$current): void {
            try {
                $account->update(['access_token' => 'new']);
            } finally {
                $current = null;
            }
        });

        $current = 'first';
        $this->assertSame('new', $accounts->find($identity)->access_token);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPartitionMustMatchPersistedAccountBeforeReadingCredentials(bool $warm): void
    {
        Schema::table('oauth_accounts', fn (Blueprint $table) => $table->string('partition_id')->nullable());
        $current = 'first';
        OAuthAccounts::resolvePartitionUsing('partition_id', static function () use (&$current): ?string {
            return $current;
        });
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'secret']);
        $this->assertSame('first', $account->partition_id);
        $accounts = $this->app->make(OAuthAccounts::class);
        $identity = $accounts->identity($account);
        if ($warm) {
            $accounts->find($identity);
        }
        $current = 'second';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('another partition');
        $accounts->find($identity);
    }

    public function testConcurrentPartitionsRenewAndReuseTheirOwnCredentials(): void
    {
        Schema::table('oauth_accounts', fn (Blueprint $table) => $table->string('partition_id')->nullable());
        OAuthAccounts::resolvePartitionUsing('partition_id', static fn (): ?string => Context::get('oauth_partition'));
        $authenticators = [];

        foreach (['first', 'second'] as $partition) {
            Context::add('oauth_partition', $partition);
            $account = Owner::create()->oauthAccounts()->create([
                'provider' => 'example',
                'access_token' => 'old-' . $partition,
                'refresh_token' => 'refresh-' . $partition,
                'expires_at' => Date::now()->subSecond(),
            ]);
            $authenticators[$partition] = $account->authenticator(function (OAuthAuthenticator $current) use ($partition): OAuthAuthenticator {
                $this->assertSame('refresh-' . $partition, $current->getRefreshToken());
                usleep(5000);
                $this->assertSame($partition, Context::get('oauth_partition'));

                return new AccessTokenAuthenticator('renewed-' . $partition);
            });
        }

        DB::releaseIdleConnections();
        $calls = [];
        foreach ($authenticators as $partition => $authenticator) {
            $calls[] = function () use ($partition, $authenticator): array {
                Context::add('oauth_partition', $partition);

                return [$this->authorization($authenticator), $this->authorization($authenticator)];
            };
        }

        $this->assertSame([
            ['Bearer renewed-first', 'Bearer renewed-first'],
            ['Bearer renewed-second', 'Bearer renewed-second'],
        ], parallel($calls));
        $this->assertSame('second', Context::get('oauth_partition'));
    }

    public function testCreationRejectsAConflictingPartition(): void
    {
        OAuthAccounts::resolvePartitionUsing('partition_id', static fn (): string => 'first');
        $account = new OAuthAccount;
        $account->forceFill(['provider' => 'example', 'access_token' => 'secret', 'partition_id' => 'second']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('another partition');
        $account->save();
    }

    public function testSavingCannotMoveAnAccountToAnotherPartition(): void
    {
        Schema::table('oauth_accounts', fn (Blueprint $table) => $table->string('partition_id')->nullable());
        OAuthAccounts::resolvePartitionUsing('partition_id', static fn (): string => 'first');
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'secret']);
        $account->partition_id = 'second';

        try {
            $account->save();
            $this->fail('An existing account must retain its partition.');
        } catch (LogicException $exception) {
            $this->assertSame('An OAuth account cannot move to another partition.', $exception->getMessage());
        }

        $this->assertSame('first', $account->fresh()->partition_id);
    }

    #[TestWith(['save'])]
    #[TestWith(['delete'])]
    public function testMutationsRejectAnotherPartitionBeforeChangingTheRow(string $operation): void
    {
        Schema::table('oauth_accounts', fn (Blueprint $table) => $table->string('partition_id')->nullable());
        $partition = 'first';
        OAuthAccounts::resolvePartitionUsing('partition_id', static function () use (&$partition): string {
            return $partition;
        });
        $account = Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'secret']);
        $authenticator = $account->authenticator(fn (): never => $this->fail('Non-expiring credentials must not refresh.'));
        $this->assertSame('Bearer secret', $this->authorization($authenticator));
        $account->access_token = 'changed';
        $partition = 'second';

        try {
            $account->{$operation}();
            $this->fail('An account mutation must reject another partition.');
        } catch (LogicException $exception) {
            $this->assertSame('The OAuth account belongs to another partition.', $exception->getMessage());
        } finally {
            $partition = 'first';
        }

        $this->assertSame('secret', $account->fresh()->access_token);
        $this->assertSame('Bearer secret', $this->authorization($authenticator));
    }

    public function testMissingPartitionContextRejectsCreation(): void
    {
        OAuthAccounts::resolvePartitionUsing('partition_id', static fn (): null => null);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('partition is not available');
        Owner::create()->oauthAccounts()->create(['provider' => 'example', 'access_token' => 'secret']);
    }

    public function testConcurrentRenewalPersistsBeforeUseAndReleasesTheOnlyDatabaseSlot(): void
    {
        $account = $this->refreshableAccount();
        $calls = 0;
        $pool = $this->app->make(PoolManager::class)->pool('oauth');
        $refresh = function (OAuthAuthenticator $current) use (&$calls, $pool): OAuthAuthenticator {
            ++$calls;
            $this->assertSame(1, $pool->getIdleCount());
            $this->assertSame('refresh', $current->getRefreshToken());
            usleep(5000);

            return new AccessTokenAuthenticator('renewed', expiresAt: Date::now()->addHour()->toDateTimeImmutable());
        };
        $first = $account->authenticator($refresh);
        $second = $account->authenticator($refresh);
        DB::releaseIdleConnections();

        $this->assertSame(['Bearer renewed', 'Bearer renewed'], parallel([
            fn (): string => $this->authorization($first),
            fn (): string => $this->authorization($second),
        ]));
        $this->assertSame(1, $calls);
        $persisted = $account->fresh();
        $this->assertSame('renewed', $persisted->access_token);
        $this->assertSame('refresh', $persisted->refresh_token);
        $this->assertSame(['read'], $persisted->scopes);
        $this->assertNull($persisted->refresh_claim);
        $this->assertNotSame($account->credential_revision, $persisted->credential_revision);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTransactionsDeferEarlyRenewalUntilActualExpiry(bool $expired): void
    {
        $account = $this->refreshableAccount();
        $authenticator = $account->authenticator(fn (): never => $this->fail('A caller transaction must not invoke the provider.'));

        if ($expired) {
            Date::setTestNow('2026-01-01 00:00:10');
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('inside a database transaction');
        }

        DB::transaction(function () use ($authenticator): void {
            $this->assertSame('Bearer old', $this->authorization($authenticator));
        });
    }

    public function testRenewalRejectsCallerTransactionsWithoutReleasingThem(): void
    {
        $account = $this->refreshableAccount();
        $entered = new Channel(1);
        $release = new Channel(1);
        $result = new Channel(1);
        $authenticator = $account->authenticator(function () use ($entered, $release): OAuthAuthenticator {
            $entered->push(true);
            $release->pop(5);

            return new AccessTokenAuthenticator('renewed');
        });
        DB::releaseIdleConnections();
        $owner = go(function () use ($authenticator, $result): void {
            try {
                $result->push($this->authorization($authenticator));
            } catch (Throwable $exception) {
                $result->push($exception);
            }
        });

        try {
            $this->assertTrue($entered->pop(2));
            DB::beginTransaction();
            try {
                $this->authorization($authenticator);
                $this->fail('A transactional caller must not wait for another renewal.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('inside a database transaction', $exception->getMessage());
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame(0, $this->app->make(PoolManager::class)->pool('oauth')->getIdleCount());
            } finally {
                DB::rollBack();
                DB::releaseIdleConnections();
                $release->push(true);
            }
            $this->assertSame('Bearer renewed', $result->pop(2));
            DB::transaction(function () use ($authenticator): void {
                $this->assertSame('Bearer renewed', $this->authorization($authenticator));
            });
        } finally {
            $release->close();
            EngineCoroutine::join([$owner], 2);
            $entered->close();
            $result->close();
        }
    }

    #[DataProvider('providerFailures')]
    public function testOnlyTransientProviderFailuresRetry(int $status, string $error, ?string $retryAfter, int $attempts): void
    {
        Sleep::fake();
        $account = $this->refreshableAccount();
        $failure = new RequestException(new Response(new PsrResponse(
            $status,
            $retryAfter === null ? [] : ['Retry-After' => $retryAfter],
            json_encode(['error' => $error]),
        )));
        $calls = 0;
        $pool = $this->app->make(PoolManager::class)->pool('oauth');
        $idleWhileWaiting = [];
        Sleep::whenFakingSleep(static function () use ($pool, &$idleWhileWaiting): void {
            $idleWhileWaiting[] = $pool->getIdleCount();
        });
        $authenticator = $account->authenticator(function () use ($failure, &$calls): never {
            ++$calls;
            throw $failure;
        });

        try {
            $this->authorization($authenticator);
            $this->fail('The provider failure must remain available to the caller.');
        } catch (RequestException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($attempts, $calls);
        $this->assertSame(array_fill(0, $attempts - 1, 1), $idleWhileWaiting);
        $this->assertNull($account->fresh()->refresh_claim);
        $this->assertSame('refresh', $account->fresh()->refresh_token);
    }

    /**
     * Provide transient and terminal provider failures.
     */
    public static function providerFailures(): array
    {
        return [
            'temporary OAuth failure' => [400, 'temporarily_unavailable', null, 3],
            'server failure' => [503, '', null, 3],
            'revoked grant wins over server status' => [503, 'invalid_grant', null, 1],
            'bad client' => [401, 'invalid_client', null, 1],
            'provider asks for longer delay' => [429, 'temporarily_unavailable', '30', 1],
        ];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testLateProviderSuccessCannotOverwriteAReconnect(bool $expired): void
    {
        $account = $this->refreshableAccount();
        $authenticator = $account->authenticator(function () use ($account, $expired): OAuthAuthenticator {
            $account->fresh()->update(['access_token' => 'reconnected', 'refresh_token' => 'new-grant', 'expires_at' => null]);

            return new AccessTokenAuthenticator('loser', 'loser-refresh', Date::now()->addSeconds($expired ? 0 : 3600)->toDateTimeImmutable());
        });

        $this->assertSame('Bearer reconnected', $this->authorization($authenticator));
        $this->assertSame('new-grant', $account->fresh()->refresh_token);
    }

    public function testFreshClaimPreventsUseUntilAbandonedEvenWhenAccessTokenIsValid(): void
    {
        $account = $this->refreshableAccount();
        $account->forceFill(['refresh_claim' => '0198f162-15b5-7000-8000-000000000001', 'refresh_started_at' => Date::now()])->save();
        $calls = 0;
        $authenticator = $account->authenticator(function () use (&$calls): OAuthAuthenticator {
            ++$calls;

            return new AccessTokenAuthenticator('recovered');
        });

        try {
            $this->authorization($authenticator);
            $this->fail('A fresh durable claim must block renewal and use of the old token.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The OAuth account is already being refreshed. Try again later.', $exception->getMessage());
        }

        $this->assertSame(0, $calls);
        Date::setTestNow('2026-01-01 00:05:05');
        $this->assertSame('Bearer recovered', $this->authorization($authenticator));
        $this->assertSame(1, $calls);
    }

    public function testInvalidGrantFromAnOldAttemptDoesNotRejectReconnectedCredentials(): void
    {
        $account = $this->refreshableAccount();
        $failure = new RequestException(new Response(new PsrResponse(400, [], '{"error":"invalid_grant"}')));
        $authenticator = $account->authenticator(function () use ($account, $failure): never {
            $account->fresh()->update(['access_token' => 'reconnected', 'refresh_token' => 'replacement', 'expires_at' => null]);
            throw $failure;
        });

        $this->assertSame('Bearer reconnected', $this->authorization($authenticator));
        $this->assertSame('replacement', $account->fresh()->refresh_token);
    }

    public function testConcurrentDeletionPreservesTheProviderRejection(): void
    {
        $account = $this->refreshableAccount();
        $failure = new RequestException(new Response(new PsrResponse(400, [], '{"error":"invalid_grant"}')));
        $authenticator = $account->authenticator(function () use ($account, $failure): never {
            $account->fresh()->delete();
            throw $failure;
        });

        try {
            $this->authorization($authenticator);
            $this->fail('The provider rejection must reach the caller.');
        } catch (RequestException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertNull($account->fresh());
    }

    public function testLateSuccessCannotOverwriteAReplacedClaimWithTheSameRevision(): void
    {
        $account = $this->refreshableAccount();
        $successor = '0198f162-15b5-7000-8000-000000000002';
        $authenticator = $account->authenticator(function () use ($account, $successor): OAuthAuthenticator {
            // Another worker reclaimed the same credential revision after the old lease expired.
            $account->newQuery()->whereKey($account->getKey())->update([
                'refresh_claim' => $successor,
                'refresh_started_at' => Date::now(),
            ]);

            return new AccessTokenAuthenticator('late-token');
        });

        try {
            $this->authorization($authenticator);
            $this->fail('A replaced claim must prevent the late provider result from being used.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The OAuth account changed during renewal. Try again with the current credentials.', $exception->getMessage());
        }

        $this->assertSame('old', $account->fresh()->access_token);
        $this->assertSame($successor, $account->fresh()->refresh_claim);
    }

    public function testExpiredProviderResultRetainsRotatedCredentialsForALaterAttempt(): void
    {
        $account = $this->refreshableAccount();
        $calls = 0;
        $authenticator = $account->authenticator(function (OAuthAuthenticator $current) use (&$calls): OAuthAuthenticator {
            ++$calls;

            if ($calls === 1) {
                return new AccessTokenAuthenticator('expired', 'rotated', Date::now()->toDateTimeImmutable());
            }

            $this->assertSame('rotated', $current->getRefreshToken());

            return new AccessTokenAuthenticator('usable');
        });

        try {
            $this->authorization($authenticator);
            $this->fail('An expired token must not be used.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('The refreshed OAuth token has already expired.', $exception->getMessage());
        }

        $this->assertSame(1, $calls);
        $this->assertSame('expired', $account->fresh()->access_token);
        $this->assertSame('rotated', $account->fresh()->refresh_token);
        $this->assertNull($account->fresh()->refresh_claim);
        $this->assertSame('Bearer usable', $this->authorization($authenticator));
        $this->assertSame(2, $calls);
    }

    public function testUnrefreshableTokenWorksUntilItsActualExpiry(): void
    {
        $account = $this->refreshableAccount();
        $account->update(['refresh_token' => null]);
        $authenticator = $account->authenticator(fn (): never => $this->fail('A missing refresh token must not call the provider.'));

        $this->assertSame('Bearer old', $this->authorization($authenticator));
        Date::setTestNow('2026-01-01 00:00:10');
        $this->expectException(OAuthAccountReconnectRequiredException::class);
        $this->expectExceptionMessage('Reconnect the account');
        $this->authorization($authenticator);
    }

    public function testCanceledOwnerCleansItsClaimAndWaiterCanRenew(): void
    {
        $account = $this->refreshableAccount();
        $entered = new Channel(1);
        $release = new Channel(1);
        $results = new Channel(2);
        $calls = 0;
        $authenticator = $account->authenticator(function () use ($entered, $release, &$calls): OAuthAuthenticator {
            if (++$calls === 1) {
                $entered->push(true);
                $release->pop(2);
                if ($release->isCanceled()) {
                    throw new CanceledException('Provider canceled.');
                }
            }

            return new AccessTokenAuthenticator('renewed');
        });
        $call = function () use ($results, $authenticator): void {
            try {
                $results->push($this->authorization($authenticator));
            } catch (Throwable $exception) {
                $results->push($exception);
            }
        };
        DB::releaseIdleConnections();
        $owner = go($call);

        try {
            $this->assertTrue($entered->pop(2));
            $waiter = go($call);
            usleep(1000);
            $this->assertTrue(EngineCoroutine::cancelById($owner));
            $this->assertInstanceOf(CanceledException::class, $results->pop(2));
            $this->assertSame('Bearer renewed', $results->pop(2));
            $this->assertSame(2, $calls);
            $this->assertNull($account->fresh()->refresh_claim);
        } finally {
            $release->close();
            EngineCoroutine::join([$owner, ...isset($waiter) ? [$waiter] : []], 2);
            $entered->close();
            $results->close();
        }
    }

    #[DefineEnvironment('configureCleanupFailingAccountModel')]
    public function testCleanupAndReportingFailuresDoNotReplaceCancellation(): void
    {
        $account = $this->refreshableAccount();
        $reported = null;
        $this->app->make(ExceptionHandler::class)->reportable(static function (RuntimeException $failure) use (&$reported): never {
            $reported = $failure;
            throw new RuntimeException('Logging unavailable.');
        });
        $cancellation = new CanceledException('Provider canceled.');
        $authenticator = $account->authenticator(static fn (): never => throw $cancellation);

        try {
            $this->authorization($authenticator);
            $this->fail('The original cancellation must reach the caller.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame('Claim cleanup failed.', $reported?->getMessage());
        $this->assertNotNull($account->fresh()->refresh_claim);
    }

    public function testConnectorConvenienceDisablesNestedRetriesAfterTheCallerModifier(): void
    {
        Sleep::fake();
        $account = $this->refreshableAccount();
        $mock = Saloon::fake([
            MockResponse::make(['error' => 'temporarily_unavailable'], 503),
            MockResponse::make(['error' => 'temporarily_unavailable'], 503),
            MockResponse::make(['access_token' => 'renewed', 'scope' => 'write']),
        ]);
        $modifier = new class {
            /**
             * Customize a refresh request through an array callable.
             */
            public function modify(Request $request): void
            {
                $request->retry(10)->withHeader('X-Refresh', 'custom');
            }
        };
        $authenticator = (new OAuth2Connector)->accountAuthenticator(
            $account,
            [$modifier, 'modify'],
        );

        $this->assertSame('Bearer renewed', $this->authorization($authenticator));
        $mock->assertSentCount(3);
        $this->assertSame('custom', $mock->lastPendingRequest()->headers()['X-Refresh']);
        $this->assertSame(1, $mock->lastPendingRequest()->request()->retryPolicy()->times);
        $this->assertSame(['write'], $account->fresh()->scopes);
    }

    public function testStreamedInvalidGrantOverridesServerStatusAndRetainsItsResponse(): void
    {
        Sleep::fake();
        $account = $this->refreshableAccount();
        $mock = Saloon::fake([new UnseekableBodyMockResponse(['error' => 'invalid_grant'], 503)]);

        try {
            $this->authorization((new OAuth2Connector)->accountAuthenticator($account));
            $this->fail('A rejected grant must be returned to the caller.');
        } catch (RequestException $exception) {
            $this->assertSame(['error' => 'invalid_grant'], $exception->response->json());
            $this->assertSame('{"error":"invalid_grant"}', $exception->response->body());
            $this->assertSame('{"error":"invalid_grant"}', (string) $exception->response->toPsrResponse()->getBody());
        }

        $mock->assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertNull($account->fresh()->refresh_claim);
    }

    public function testGuzzleResponseTransferFailuresRetryWithoutReadingTheIncompleteBody(): void
    {
        Sleep::fake();
        $account = $this->refreshableAccount();
        $reads = 0;
        $calls = 0;
        $body = new PumpStream(static function () use (&$reads): false {
            ++$reads;

            return false;
        });
        $failure = new ResponseTransferException('Transfer failed.', new PsrRequest('POST', 'https://example.test/token'), new PsrResponse(200, [], $body));
        $authenticator = $account->authenticator(function () use ($failure, &$calls): never {
            ++$calls;
            throw $failure;
        });

        try {
            $this->authorization($authenticator);
            $this->fail('The transfer failure must be returned to the caller.');
        } catch (ResponseTransferException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(3, $calls);
        $this->assertSame(0, $reads);
        $this->assertNull($account->fresh()->refresh_claim);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPartitionIsRecheckedAfterProviderYieldBeforeWritingOrUsingCredentials(bool $cancel): void
    {
        Schema::table('oauth_accounts', fn (Blueprint $table) => $table->string('partition_id')->nullable());
        $current = 'first';
        OAuthAccounts::resolvePartitionUsing('partition_id', static function () use (&$current): string {
            return $current;
        });
        $account = $this->refreshableAccount();
        $authenticator = $account->authenticator(function () use (&$current, $cancel): OAuthAuthenticator {
            usleep(1000);
            $current = 'second';

            if ($cancel) {
                throw new CanceledException('Provider canceled.');
            }

            return new AccessTokenAuthenticator('wrong-context');
        });

        try {
            $this->authorization($authenticator);
            $this->fail('Context changes must prevent credential writes and use.');
        } catch (LogicException|CanceledException $exception) {
            $this->assertSame($cancel ? 'Provider canceled.' : 'The OAuth account belongs to another partition.', $exception->getMessage());
        } finally {
            $current = 'first';
        }

        $this->assertSame('old', $account->fresh()->access_token);
        $this->assertNull($account->fresh()->refresh_claim);
    }

    /**
     * Create an account due for renewal but whose old token has not yet expired.
     */
    protected function refreshableAccount(): OAuthAccount
    {
        $account = Owner::create()->oauthAccounts()->create([
            'provider' => 'example',
            'access_token' => 'old',
            'refresh_token' => 'refresh',
            'expires_at' => Date::now()->addSeconds(10),
            'scopes' => ['read'],
        ]);
        Date::setTestNow('2026-01-01 00:00:05');

        return $account;
    }

    /**
     * Apply account authentication to a real pending request.
     */
    protected function authorization(OAuthAccountAuthenticator $authenticator): string
    {
        return (new TestConnector)->createPendingRequest(UserRequest::make()->authenticate($authenticator))->headers()['Authorization'];
    }
}

class Owner extends Model
{
    use HasOAuthAccounts;

    public bool $timestamps = false;

    protected array $guarded = [];
}

class CustomAccount extends OAuthAccount
{
    protected ?string $table = 'custom_oauth_accounts';

    /**
     * Apply custom authentication to the stored credentials.
     */
    public function newOAuthAuthenticator(): OAuthAuthenticator
    {
        return new AccessTokenAuthenticator('custom:' . $this->access_token, $this->refresh_token, $this->expires_at, $this->scopes);
    }
}

class GuardedAccount extends OAuthAccount
{
    /**
     * Create the account's guarded query builder.
     */
    public function newEloquentBuilder(QueryBuilder $query): Builder
    {
        return new GuardedAccountBuilder($query);
    }
}

class GuardedAccountBuilder extends Builder
{
    /**
     * Reject credential writes while allowing claim coordination.
     */
    public function update(array $values): int
    {
        if (array_key_exists('access_token', $values)) {
            throw new RuntimeException('Credential writes are denied.');
        }

        return parent::update($values);
    }
}

class CleanupFailingAccount extends OAuthAccount
{
    /**
     * Create a builder that rejects claim cleanup.
     */
    public function newEloquentBuilder(QueryBuilder $query): Builder
    {
        return new CleanupFailingAccountBuilder($query);
    }
}

class CleanupFailingAccountBuilder extends Builder
{
    /**
     * Allow claiming, then fail the cleanup write.
     */
    public function update(array $values): int
    {
        if (array_key_exists('refresh_claim', $values) && $values['refresh_claim'] === null) {
            throw new RuntimeException('Claim cleanup failed.');
        }

        return parent::update($values);
    }
}
