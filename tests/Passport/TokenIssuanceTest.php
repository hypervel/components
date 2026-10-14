<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use DateInterval;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Bridge\AccessToken;
use Hypervel\Passport\Bridge\AccessTokenRepository;
use Hypervel\Passport\Bridge\AuthCodeRepository;
use Hypervel\Passport\Bridge\Client;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Bridge\RefreshToken as RefreshTokenEntity;
use Hypervel\Passport\Bridge\RefreshTokenRepository;
use Hypervel\Passport\Bridge\SigningCryptKey;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Events\AccessTokenCreated;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Passport\Events\RefreshTokenCreated;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\RefreshTokenFamily;
use Hypervel\Passport\ServerEntry;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerSettings;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\StoredServerKey;
use Hypervel\Passport\Token;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class TokenIssuanceTest extends TestCase
{
    protected ServerFactory $servers;

    protected ServerEntry $entry;

    protected SigningKey $key;

    /**
     * Configure persistence without booting the package's command and route services.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('passport', require __DIR__ . '/../../src/passport/config/passport.php');
    }

    /**
     * Prepare credential tables and a stored signing key for issuance tests.
     */
    protected function setUpInCoroutine(): void
    {
        foreach ([
            '2016_06_01_000001_create_oauth_auth_codes_table.php',
            '2016_06_01_000002_create_oauth_access_tokens_table.php',
            '2016_06_01_000003_create_oauth_refresh_tokens_table.php',
            '2016_06_01_000006_create_oauth_refresh_token_families_table.php',
            '2016_06_01_000007_create_oauth_signing_keys_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../src/passport/database/migrations/' . $migration)->up();
        }

        $this->key = SigningKey::factory()->create();
        $private = ParsedKey::fromPrivateKey($this->key->private_key);
        $public = ParsedKey::fromPublicKey($this->key->public_key);
        $key = new StoredServerKey($this->key->id, 0, $this->key->public_key, $this->key->private_key, $this->key->activates_at);
        $this->entry = new ServerEntry(
            new ServerSettings([$key]),
            [$private->getKeyId() => $key],
            [$private->getKeyId() => $public],
            [$private->getKeyId() => new SigningCryptKey($private)],
        );
        $this->servers = m::mock(ServerFactory::class);
        $this->servers->shouldReceive('entry')->andReturn($this->entry);
    }

    #[DataProvider('outcomes')]
    public function testGrantAndTokenWritesAndEventsFollowTheCompletedResponse(bool $succeeds): void
    {
        $code = $this->code();
        $events = [];
        Event::listen(AccessTokenCreated::class, static function (AccessTokenCreated $event) use (&$events): void {
            $events[] = $event;
        });
        $failure = new RuntimeException('Response generation failed.');

        try {
            TokenIssuance::run($this->servers, function () use ($code, $succeeds, $failure, &$events): void {
                $this->assertFalse((new AuthCodeRepository)->isAuthCodeRevoked($code->id));
                TokenIssuance::current()->claimGrant();
                $this->app->make(AccessTokenRepository::class)->persistNewAccessToken($this->accessToken('access'));
                $this->assertSame([], $events);

                if (! $succeeds) {
                    throw $failure;
                }
            }, tokenRequest: true);
            $this->assertTrue($succeeds);
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertFalse($succeeds);
        }

        $this->assertSame($succeeds, $code->fresh()->revoked);
        $this->assertSame($succeeds ? 1 : 0, Token::count());
        $this->assertCount($succeeds ? 1 : 0, $events);
        $this->assertNull(TokenIssuance::current());
        $this->assertSame(0, DB::transactionLevel());
    }

    /**
     * Exercise successful and failed responses.
     */
    public static function outcomes(): iterable
    {
        yield 'success' => [true];
        yield 'failure' => [false];
    }

    public function testFailedDirectRefreshPersistenceRollsBackItsFamilyAndEvent(): void
    {
        $events = [];
        Event::listen(RefreshTokenCreated::class, static function (RefreshTokenCreated $event) use (&$events): void {
            $events[] = $event;
        });
        $failure = new RuntimeException('Refresh token insert failed.');
        RefreshToken::creating(static function () use ($failure): never {
            throw $failure;
        });
        $entity = new RefreshTokenEntity;
        $entity->setIdentifier('refresh');
        $entity->setAccessToken($this->accessToken('access'));
        $entity->setExpiryDateTime(new CarbonImmutable('+1 day'));

        try {
            $this->app->make(RefreshTokenRepository::class)->persistNewRefreshToken($entity);
            $this->fail('The persistence failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, RefreshTokenFamily::count());
        $this->assertSame(0, RefreshToken::count());
        $this->assertSame([], $events);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testReplayingARevokedFamilyDoesNotRewriteItOrDispatchEvents(): void
    {
        $family = RefreshTokenFamily::create();
        Token::forceCreate(['id' => 'access', 'client_id' => 'client', 'revoked' => false]);
        $refresh = RefreshToken::forceCreate([
            'id' => 'refresh', 'access_token_id' => 'access', 'family_id' => $family->id, 'revoked' => true,
        ]);
        $repository = $this->app->make(RefreshTokenRepository::class);
        $this->assertTrue($repository->isRefreshTokenRevoked($refresh->id));
        $this->assertTrue(Token::findOrFail('access')->revoked);
        $revokedAt = $family->fresh()->revoked_at;
        $this->assertNotNull($revokedAt);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        Event::fake([AccessTokenRevoked::class]);
        DB::enableQueryLog();

        try {
            $this->assertTrue($repository->isRefreshTokenRevoked($refresh->id));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query']);
        }

        $this->assertEquals($revokedAt, $family->fresh()->revoked_at);
        Event::assertNotDispatched(AccessTokenRevoked::class);
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function testRollbackFailuresPreserveTheIssuanceErrorAndRestoreContext(bool $canceled, bool $cleanupCanceled): void
    {
        $failure = $canceled ? new CanceledException('Issuance canceled.') : new RuntimeException('Issuance failed.');
        $cleanup = $cleanupCanceled ? new CanceledException('Rollback canceled.') : new RuntimeException('Rollback failed.');

        TokenIssuance::run($this->servers, function () use ($failure, $cleanup, $canceled, $cleanupCanceled): void {
            $outer = TokenIssuance::current();

            try {
                TokenIssuance::run($this->servers, static function () use ($failure, $cleanup): never {
                    TokenIssuance::current()->begin();
                    DB::afterRollBack(static function () use ($cleanup): never {
                        throw $cleanup;
                    });

                    throw $failure;
                });
                $this->fail('The issuance failure must propagate.');
            } catch (Throwable $exception) {
                $this->assertSame($cleanupCanceled && ! $canceled ? $cleanup : $failure, $exception);
            }

            $this->assertSame($outer, TokenIssuance::current());
            $this->assertSame(0, DB::transactionLevel());
        });

        $this->assertNull(TokenIssuance::current());
    }

    public function testLostRefreshClaimRevokesTheFamilyAfterRollingBackItsIssuance(): void
    {
        $family = RefreshTokenFamily::create();
        $token = Token::forceCreate(['id' => 'old-access', 'client_id' => 'client', 'revoked' => false]);
        $refresh = RefreshToken::forceCreate(['id' => 'old-refresh', 'access_token_id' => $token->id, 'family_id' => $family->id, 'revoked' => false]);
        $events = [];
        Event::listen(AccessTokenRevoked::class, static function (AccessTokenRevoked $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            TokenIssuance::run($this->servers, function () use ($refresh, $token): void {
                $issuance = TokenIssuance::current();
                $issuance->setGrant($refresh);
                RefreshToken::whereKey($refresh->id)->update(['revoked' => true]);
                $this->app->make(AccessTokenRepository::class)->revokeAccessToken($token->id);
                $issuance->claimGrant();
            });
            $this->fail('The lost refresh claim must reject the request.');
        } catch (OAuthServerException $exception) {
            $this->assertSame('invalid_grant', $exception->getErrorType());
        }

        $this->assertNotNull($family->fresh()->revoked_at);
        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);
        $this->assertCount(1, $events);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testRetentionBoundIsPublishedOnlyAfterCommitAndARollbackCanRetry(): void
    {
        $failure = new RuntimeException('Signing failed.');

        try {
            TokenIssuance::run($this->servers, function () use ($failure): void {
                TokenIssuance::current()->retainSigningKey(new CarbonImmutable('+1 hour'), new DateInterval('PT1H'));
                $this->assertGreaterThanOrEqual(3600, $this->key->fresh()->max_token_lifetime);
                $this->assertSame(0, $this->entry->retentionBounds[$this->key->id]);

                throw $failure;
            });
            $this->fail('The response failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $this->key->fresh()->max_token_lifetime);
        $this->assertSame(0, $this->entry->retentionBounds[$this->key->id]);
        TokenIssuance::run($this->servers, static function (): void {
            TokenIssuance::current()->retainSigningKey(new CarbonImmutable('+1 hour'), new DateInterval('PT1H'));
        });
        $this->assertGreaterThanOrEqual(3600, $this->key->fresh()->max_token_lifetime);
        $this->assertSame($this->key->fresh()->max_token_lifetime, $this->entry->retentionBounds[$this->key->id]);
    }

    #[TestWith(['P1M', 31 * 86400])]
    #[TestWith(['P1Y', 366 * 86400])]
    public function testCalendarRetentionCoversTheLongestPossibleInterval(string $interval, int $seconds): void
    {
        $now = new CarbonImmutable('2032-02-01 00:00:00');
        CarbonImmutable::setTestNow($now);
        $lifetime = new DateInterval($interval);

        TokenIssuance::run($this->servers, static function () use ($now, $lifetime): void {
            TokenIssuance::current()->retainSigningKey($now->add($lifetime), $lifetime);
        });

        $this->assertSame($seconds, $this->key->fresh()->max_token_lifetime);
        $this->assertSame($seconds, $this->entry->retentionBounds[$this->key->id]);
    }

    public function testRetentionIncludesTimeSpentIssuingAfterTheKeyWasPinned(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);

        TokenIssuance::run($this->servers, static function () use ($now): void {
            CarbonImmutable::setTestNow($now->addHours(2));
            TokenIssuance::current()->retainSigningKey(CarbonImmutable::now()->addHour(), new DateInterval('PT1H'));
        });

        $this->assertSame(3 * 3600, $this->key->fresh()->max_token_lifetime);
    }

    #[TestWith([3600])]
    #[TestWith([7200])]
    public function testAWorkerWithAnOlderBoundAcceptsAnEqualOrLargerStoredBound(int $storedSeconds): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $this->key->forceFill(['max_token_lifetime' => $storedSeconds])->save();

        TokenIssuance::run($this->servers, static function () use ($now): void {
            TokenIssuance::current()->retainSigningKey($now->addHour(), new DateInterval('PT1H'));
        });

        $this->assertSame($storedSeconds, $this->key->fresh()->max_token_lifetime);
        $this->assertSame($storedSeconds, $this->entry->retentionBounds[$this->key->id]);
    }

    public function testAKeyRemovedDuringIssuanceFailsAndRollsBackTheToken(): void
    {
        $this->key->delete();

        try {
            TokenIssuance::run($this->servers, function (): void {
                $this->app->make(AccessTokenRepository::class)->persistNewAccessToken($this->accessToken('access'));
                TokenIssuance::current()->retainSigningKey(new CarbonImmutable('+1 hour'));
            });
            $this->fail('Issuance must not return a token whose key has been removed.');
        } catch (LogicException $exception) {
            $this->assertSame('The Passport signing key was removed during token issuance.', $exception->getMessage());
        }

        $this->assertSame(0, Token::count());
        $this->assertSame(0, $this->entry->retentionBounds[$this->key->id]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testRetentionWaitsForTheCallersTransactionAndCanRetryAfterRollback(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        DB::beginTransaction();

        try {
            TokenIssuance::run($this->servers, static function () use ($now): void {
                TokenIssuance::current()->retainSigningKey($now->addHour(), new DateInterval('PT1H'));
            });
            $this->assertSame(3600, $this->key->fresh()->max_token_lifetime);
            $this->assertSame(0, $this->entry->retentionBounds[$this->key->id]);
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, $this->key->fresh()->max_token_lifetime);
        TokenIssuance::run($this->servers, static function () use ($now): void {
            TokenIssuance::current()->retainSigningKey($now->addHour(), new DateInterval('PT1H'));
        });
        $this->assertSame(3600, $this->key->fresh()->max_token_lifetime);
        $this->assertSame(3600, $this->entry->retentionBounds[$this->key->id]);
    }

    public function testNestedIssuanceDoesNotInheritItsParentsAttributesAndRestoresItAfterFailure(): void
    {
        TokenIssuance::run($this->servers, function (): void {
            $outer = TokenIssuance::current();
            $failure = new RuntimeException('Nested response failed.');

            try {
                TokenIssuance::run($this->servers, function () use ($outer, $failure): void {
                    $this->assertNotSame($outer, TokenIssuance::current());
                    $this->assertSame([], TokenIssuance::current()->attributes());

                    throw $failure;
                });
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertSame($outer, TokenIssuance::current());
            $this->assertSame(['provider' => 'users'], $outer->attributes());
        }, ['provider' => 'users']);

        $this->assertNull(TokenIssuance::current());
    }

    public function testCopiedCoroutineContextDoesNotShareTheIssuanceOrItsTransaction(): void
    {
        TokenIssuance::run($this->servers, function (): void {
            $issuance = TokenIssuance::current();
            $issuance->begin();
            [$child] = parallel([static fn (): ?TokenIssuance => TokenIssuance::current()], copyContext: true);

            $this->assertNull($child);
            $this->assertSame($issuance, TokenIssuance::current());
            $this->assertSame(1, DB::transactionLevel());
        });

        $this->assertSame(0, DB::transactionLevel());
    }

    /**
     * Create a redeemable authorization code.
     */
    protected function code(): AuthCode
    {
        return AuthCode::forceCreate(['id' => 'code', 'user_id' => 1, 'client_id' => 'client', 'provider' => 'users', 'revoked' => false]);
    }

    /**
     * Create a bridge entity ready for persistence.
     */
    protected function accessToken(string $id): AccessToken
    {
        $token = new AccessToken('1', [], new Client('client', 'Client', [], true));
        $token->setIdentifier($id);
        $token->setExpiryDateTime(new CarbonImmutable('+1 hour'));

        return $token;
    }
}
