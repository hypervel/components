<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Redis;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenAcquisition;
use Hypervel\Saloon\OAuthAccount;
use Hypervel\Saloon\OAuthAccounts;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\go;

class OAuthAccountRenewalTest extends TestCase
{
    use InteractsWithRedis;

    protected ?string $migration = null;

    /**
     * Register Saloon for shared-store renewal tests.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Use the isolated Redis connection for credentials and renewal locks.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('saloon.oauth_accounts.cache', ['enabled' => true, 'store' => 'redis']);
    }

    /**
     * Create the account schema through its migration command.
     */
    protected function setUpInCoroutine(): void
    {
        Date::setTestNow('2026-01-01 00:00:00');
        $this->artisan('make:oauth-accounts-table')->assertSuccessful();
        $files = $this->app->make('files')->glob($this->app->databasePath('migrations/*_create_oauth_accounts_table.php'));
        $this->migration = $files[0];
        (require $this->migration)->up();
    }

    /**
     * Remove the generated migration.
     */
    protected function tearDown(): void
    {
        try {
            if ($this->migration !== null) {
                $this->app->make('files')->delete($this->migration);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testDatabaseClaimSurvivesLostRedisLockAndFencesLateCompletion(bool $abandoned): void
    {
        $account = new OAuthAccount;
        $account->forceFill([
            'owner_type' => 'test', 'owner_id' => 1,
            'provider' => 'example', 'access_token' => 'old', 'refresh_token' => 'refresh',
            'expires_at' => Date::now()->subMinute(),
        ])->save();
        $first = $this->worker('first');
        $second = $this->worker('second');
        $identity = $first->identity($account);
        $entered = new Channel(1);
        $release = new Channel(1);
        $result = new Channel(1);
        DB::releaseIdleConnections();
        $owner = go(function () use ($first, $identity, $entered, $release, $result): void {
            try {
                $result->push($first->token($identity, function () use ($entered, $release): OAuthAuthenticator {
                    $entered->push(true);
                    if ($release->pop(5) !== true) {
                        throw new RuntimeException('Provider release was not received.');
                    }

                    return new AccessTokenAuthenticator('first-result');
                })->getAccessToken());
            } catch (Throwable $exception) {
                $result->push($exception);
            }
        });

        try {
            $this->assertTrue($entered->pop(5));
            $this->app->make('cache')->store('redis')->lock('saloon:oauth-renewal:' . $identity->key)->forceRelease();

            if ($abandoned) {
                Date::setTestNow('2026-01-01 00:05:00');
                $replacement = $second->token($identity, fn (): OAuthAuthenticator => new AccessTokenAuthenticator('replacement'));
                $this->assertSame('replacement', $replacement->getAccessToken());
            } else {
                try {
                    $second->token($identity, fn (): never => $this->fail('A fresh row claim must prevent a second provider call.'));
                    $this->fail('The lost Redis lease must not bypass a fresh row claim.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('The OAuth account is already being refreshed. Try again later.', $exception->getMessage());
                }
            }

            DB::releaseIdleConnections();
            $release->push(true);
            $expected = $abandoned ? 'replacement' : 'first-result';
            $this->assertSame($expected, $result->pop(5));
            $this->assertSame($expected, $account->fresh()->access_token);
            $this->assertNull($account->fresh()->refresh_claim);
        } finally {
            $release->close();
            EngineCoroutine::join([$owner], 5);
            $entered->close();
            $result->close();
        }
    }

    /**
     * Isolate worker-local coordination while exercising the real Redis and database protocol.
     */
    protected function worker(string $name): OAuthAccounts
    {
        return $this->app->make(OAuthAccounts::class, ['acquisition' => new WorkerTokenAcquisition($name)]);
    }
}

class WorkerTokenAcquisition extends TokenAcquisition
{
    /**
     * Select an independent worker-local coordination namespace.
     */
    public function __construct(private readonly string $worker)
    {
    }

    /**
     * Coordinate only callers belonging to this simulated worker.
     */
    public function run(string $key, Closure $acquire): mixed
    {
        return parent::run($this->worker . ':' . $key, $acquire);
    }
}
