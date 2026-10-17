<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Client;
use Hypervel\Passport\Consent;
use Hypervel\Passport\ConsentRepository;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\PushedAuthorizationRequest;
use Hypervel\Passport\PushedAuthorizationRequestRepository;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\RefreshTokenFamily;
use Hypervel\Passport\ReplayCache;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Coroutine\parallel;

#[ResetRefreshDatabaseState]
abstract class GrantConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * Register the real grant and repository implementations.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Give concurrent exchanges their own pooled database sessions.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $connection = $config->string('database.default');
        $keys = (new SigningKeyFactory)->definition();

        $config->set([
            "database.connections.{$connection}.pool.testing_enabled" => true,
            'passport.private_key' => $keys['private_key'],
            'passport.public_key' => $keys['public_key'],
        ]);
    }

    /**
     * Create the client and credential tables used by the grants.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        foreach ([
            '2016_06_01_000001_create_oauth_auth_codes_table.php',
            '2016_06_01_000002_create_oauth_access_tokens_table.php',
            '2016_06_01_000003_create_oauth_refresh_tokens_table.php',
            '2016_06_01_000004_create_oauth_clients_table.php',
            '2016_06_01_000006_create_oauth_refresh_token_families_table.php',
            '2024_06_01_000001_create_oauth_device_codes_table.php',
            '2026_10_17_000001_create_oauth_consents_table.php',
            '2026_10_17_000002_create_oauth_pushed_authorization_requests_table.php',
        ] as $migration) {
            (require dirname(__DIR__, 4) . '/src/passport/database/migrations/' . $migration)->up();
        }
    }

    public function testConcurrentReplayClaimsAcceptOnlyOneRequest(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('passport_replays', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        config([
            'passport.replay.store' => 'passport-replay',
            'cache.stores.passport-replay' => [
                'driver' => 'database',
                'connection' => config('database.default'),
                'table' => 'passport_replays',
                'prefix' => '',
            ],
        ]);
        $ready = new Channel(2);
        $release = new Channel(2);
        $waiting = true;

        // Both requests must miss before either writes its claim.
        DB::listen(function (QueryExecuted $query) use ($ready, $release, &$waiting): void {
            if ($waiting && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'passport_replays')) {
                $ready->push(true);
                $this->assertTrue($release->pop(5));
            }
        });
        $replays = $this->app->make(ReplayCache::class);
        $expiresAt = time() + 300;
        $claim = static fn (): bool => $replays->consume('client:assertion', $expiresAt);

        try {
            [$first, $second] = parallel([
                $claim,
                $claim,
                function () use ($ready, $release): void {
                    try {
                        $this->assertTrue($ready->pop(5));
                        $this->assertTrue($ready->pop(5));
                    } finally {
                        $release->push(true);
                        $release->push(true);
                    }
                },
            ]);
            $waiting = false;

            $this->assertEqualsCanonicalizing([true, false], [$first, $second]);
            $this->assertFalse($claim());
            $this->assertSame(1, DB::table('passport_replays')->count());
        } finally {
            $waiting = false;
            $schema->drop('passport_replays');
        }
    }

    public function testConcurrentPushedRequestConsumptionAcceptsOnlyOneBrowser(): void
    {
        $client = ClientFactory::new()->create();
        $uri = 'urn:ietf:params:oauth:request_uri:test-reference';
        PushedAuthorizationRequest::create([
            'id' => hash('sha256', $uri), 'client_id' => $client->id,
            'parameters' => ['client_id' => $client->id, 'response_type' => 'code'],
            'scopes' => [], 'redirect_uri' => $client->redirect_uris[0], 'expires_at' => now()->addMinute(),
        ]);
        $repository = $this->app->make(PushedAuthorizationRequestRepository::class);

        try {
            $repository->consume($uri, 'not-a-uuid');
            $this->fail('A malformed client identifier must not consume the reference.');
        } catch (OAuthServerException $exception) {
            $this->assertSame('invalid_request_uri', $exception->getErrorType());
        }

        $ready = new Channel(2);
        $release = new Channel(2);
        $waiting = true;
        DB::listen(function (QueryExecuted $query) use ($ready, $release, &$waiting): void {
            if ($waiting && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'oauth_pushed_authorization_requests')) {
                $ready->push(true);
                $this->assertTrue($release->pop(5));
            }
        });
        $consume = static function () use ($repository, $uri, $client): string {
            try {
                $repository->consume($uri, $client->id);

                return 'accepted';
            } catch (OAuthServerException $exception) {
                return $exception->getErrorType();
            }
        };

        try {
            [$first, $second] = parallel([
                $consume,
                $consume,
                function () use ($ready, $release): void {
                    try {
                        $this->assertTrue($ready->pop(5));
                        $this->assertTrue($ready->pop(5));
                    } finally {
                        $release->push(true);
                        $release->push(true);
                    }
                },
            ]);
        } finally {
            $waiting = false;
        }

        $this->assertEqualsCanonicalizing(['accepted', 'invalid_request_uri'], [$first, $second]);
        $this->assertSame(0, PushedAuthorizationRequest::count());
    }

    #[TestWith([2, false])]
    #[TestWith([3, false])]
    #[TestWith([3, true])]
    public function testConcurrentApprovalsCreateOneConsentWithoutLosingChoices(int $approvals, bool $outerTransaction): void
    {
        $client = ClientFactory::new()->create();
        $repository = $this->app->make(ConsentRepository::class);
        $servers = $this->app->make(ServerFactory::class);
        $ready = new Channel($approvals);
        $release = new Channel($approvals);
        $remaining = $approvals;
        Consent::creating(function () use ($ready, $release, &$remaining): void {
            if ($remaining > 0) {
                --$remaining;
                $ready->push(true);
                $this->assertTrue($release->pop(5));
            }
        });
        $approve = static function (string $scope) use ($repository, $client, $servers, $outerTransaction): string {
            $issue = static fn (): string => TokenIssuance::run($servers, static function () use ($repository, $client, $scope): string {
                $consent = $repository->lockForUser($client->id, 'users', 1);
                $selection = ['scopes' => [$scope], 'permissions' => []];
                $repository->remember($consent, $selection, $selection, null);

                return $consent->id;
            });

            return $outerTransaction ? DB::transaction($issue, 2) : $issue();
        };
        $scopes = array_slice(['read', 'write', 'delete'], 0, $approvals);
        $tasks = array_map(static fn (string $scope): Closure => static fn (): string => $approve($scope), $scopes);
        $tasks[] = function () use ($ready, $release, $approvals): void {
            try {
                for ($i = 0; $i < $approvals; ++$i) {
                    $this->assertTrue($ready->pop(5));
                }
            } finally {
                for ($i = 0; $i < $approvals; ++$i) {
                    $release->push(true);
                }
            }
        };
        $results = parallel($tasks);
        array_pop($results);

        $this->assertCount(1, array_unique($results));
        $this->assertEqualsCanonicalizing($scopes, Consent::sole()->scopes);
    }

    public function testApprovalCreatesANewConsentWhenRevokedAfterTheInitialLookup(): void
    {
        $consent = Consent::factory()->create(['scopes' => ['old']]);
        $ready = new Channel(1);
        $release = new Channel(1);
        $firstRead = true;
        Consent::retrieved(function (Consent $record) use ($consent, $ready, $release, &$firstRead): void {
            if ($firstRead && $record->id === $consent->id) {
                $firstRead = false;
                $ready->push(true);
                $this->assertTrue($release->pop(5));
            }
        });
        $repository = $this->app->make(ConsentRepository::class);
        $servers = $this->app->make(ServerFactory::class);

        [$id] = parallel([
            static fn (): string => TokenIssuance::run($servers, static function () use ($consent, $repository): string {
                $current = $repository->lockForUser($consent->client_id, $consent->provider, $consent->user_id);
                $selection = ['scopes' => ['read'], 'permissions' => []];
                $repository->remember($current, $selection, $selection, null);

                return $current->id;
            }),
            function () use ($consent, $ready, $release): void {
                try {
                    $this->assertTrue($ready->pop(5));
                    $consent->revoke();
                } finally {
                    $release->push(true);
                }
            },
        ]);

        $this->assertNotSame($consent->id, $id);
        $this->assertSame($id, Consent::sole()->id);
        $this->assertSame(['read'], Consent::sole()->scopes);
    }

    #[TestWith(['authorization_code'])]
    #[TestWith(['device_code'])]
    #[TestWith(['refresh_token'])]
    public function testConcurrentRedemptionsIssueOnlyOneSuccessorPair(string $grant): void
    {
        $client = $grant === 'device_code'
            ? ClientFactory::new()->asDeviceCodeClient()->create()
            : ClientFactory::new()->create();
        $request = $this->grantRequest($client, $grant);
        $model = match ($grant) {
            'authorization_code' => AuthCode::class,
            'device_code' => DeviceCode::class,
            'refresh_token' => RefreshToken::class,
        };
        $credential = $model::sole();
        $ready = new Channel(2);
        $release = new Channel(2);
        $waiting = true;

        // Both requests must read the unclaimed row before either attempts its conditional update.
        $model::retrieved(function (Model $record) use ($credential, $ready, $release, &$waiting): void {
            if ($waiting && $record->getKey() === $credential->getKey()) {
                $ready->push(true);
                $this->assertTrue($release->pop(5));
            }
        });

        $exchange = function () use ($request): array {
            try {
                return $this->exchange($request);
            } catch (OAuthServerException $exception) {
                return ['error' => $exception->getErrorType()];
            }
        };

        try {
            [$first, $second] = parallel([
                $exchange,
                $exchange,
                function () use ($ready, $release): void {
                    try {
                        $this->assertTrue($ready->pop(5));
                        $this->assertTrue($ready->pop(5));
                    } finally {
                        $release->push(true);
                        $release->push(true);
                    }
                },
            ]);
        } finally {
            $waiting = false;
        }

        $this->assertCount(1, array_column([$first, $second], 'access_token'));
        $this->assertCount(1, array_column([$first, $second], 'refresh_token'));
        $this->assertSame(['invalid_grant'], array_values(array_column([$first, $second], 'error')));
        $this->assertTrue($credential->fresh()->revoked);
        $this->assertSame($grant === 'refresh_token' ? 2 : 1, Token::count());
        $this->assertSame($grant === 'refresh_token' ? 2 : 1, RefreshToken::count());
        $this->assertSame(1, RefreshTokenFamily::count());

        if ($grant === 'refresh_token') {
            $this->assertNotNull(RefreshTokenFamily::sole()->revoked_at);
            $this->assertFalse(Token::where('revoked', false)->exists());
            $this->assertFalse(RefreshToken::where('revoked', false)->exists());
        }
    }

    public function testFamilyRevocationIncludesAnInFlightRefreshSuccessor(): void
    {
        $client = ClientFactory::new()->create();
        $request = $this->grantRequest($client, 'refresh_token');
        $family = RefreshTokenFamily::sole();
        $ready = new Channel(1);
        $started = new Channel(1);

        RefreshToken::created(function () use ($ready, $started): void {
            $ready->push(true);
            $this->assertTrue($started->pop(5));
            usleep(10000);
        });

        [$response] = parallel([
            fn (): array => $this->exchange($request),
            function () use ($ready, $started, $family): void {
                $this->assertTrue($ready->pop(5));
                $started->push(true);
                TokenIssuance::revokeRefreshTokenFamily($family->getKey());
            },
        ]);

        $this->assertArrayHasKey('access_token', $response);
        $this->assertSame(2, Token::count());
        $this->assertSame(2, RefreshToken::count());
        $this->assertNotNull($family->fresh()->revoked_at);
        $this->assertFalse(Token::where('revoked', false)->exists());
        $this->assertFalse(RefreshToken::where('revoked', false)->exists());
    }

    #[TestWith(['authorization_code', false])]
    #[TestWith(['device_code', false])]
    #[TestWith(['refresh_token', false])]
    #[TestWith(['authorization_code', true])]
    #[TestWith(['device_code', true])]
    #[TestWith(['refresh_token', true])]
    public function testConsentRevocationSerializesWithCredentialExchange(string $grant, bool $revokeFirst): void
    {
        $client = $grant === 'device_code'
            ? ClientFactory::new()->asDeviceCodeClient()->create()
            : ClientFactory::new()->create();
        $consent = Consent::factory()->create(['client_id' => $client->id, 'user_id' => 1]);
        $request = $this->grantRequest($client, $grant, $consent);
        $ready = new Channel(1);
        $started = new Channel(1);
        $pause = function () use ($ready, $started): void {
            $ready->push(true);
            $this->assertTrue($started->pop(5));
            usleep(10000);
        };

        if ($revokeFirst) {
            Consent::deleted($pause);
        } else {
            Token::created($pause);
        }

        [$response] = parallel([
            function () use ($request, $revokeFirst, $ready, $started): array {
                if ($revokeFirst) {
                    $this->assertTrue($ready->pop(5));
                    $started->push(true);
                }

                try {
                    return $this->exchange($request);
                } catch (OAuthServerException $exception) {
                    return ['error' => $exception->getErrorType()];
                }
            },
            fn () => DB::transaction(function () use ($consent, $revokeFirst, $ready, $started): void {
                // An existing MySQL snapshot must not hide the products of the exchange we wait for.
                Token::count();

                if (! $revokeFirst) {
                    $this->assertTrue($ready->pop(5));
                    $started->push(true);
                }

                $consent->revoke();
            }),
        ]);

        if ($revokeFirst) {
            $this->assertSame(['error' => 'invalid_grant'], $response);
        } else {
            $this->assertArrayHasKey('access_token', $response);
        }

        $this->assertSame(($grant === 'refresh_token' ? 1 : 0) + ($revokeFirst ? 0 : 1), Token::count());
        $this->assertSame(0, Consent::count());
        $this->assertFalse(Token::where('revoked', false)->exists());
        $this->assertFalse(RefreshToken::where('revoked', false)->exists());
        $this->assertFalse(RefreshTokenFamily::whereNull('revoked_at')->exists());
    }

    public function testDeviceUserCodeCollisionIsRetriedInsideACallerTransaction(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();
        $this->grantRequest($client, 'device_code');
        $existing = DeviceCode::sole();
        $attempts = 0;
        DeviceCode::creating(static function (DeviceCode $code) use ($existing, &$attempts): void {
            if (++$attempts === 1) {
                $code->user_code = $existing->user_code;
            }
        });
        $request = DB::transaction(fn (): array => $this->grantRequest($client, 'device_code'));
        $created = DeviceCode::findOrFail($request['device_code']);

        $this->assertSame(2, $attempts);
        $this->assertSame(2, DeviceCode::count());
        $this->assertNotSame($existing->user_code, $created->user_code);
        $this->assertNotNull($created->user_approved_at);
        $this->assertArrayHasKey('access_token', $this->exchange($request));
    }

    public function testDevicePollingRemainsCommittedAfterOAuthErrors(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();
        $response = $this->app->make(ServerFactory::class)->authorizationServer()->respondToDeviceAuthorizationRequest(
            (new ServerRequest('POST', 'https://example.test/oauth/device/code'))->withParsedBody([
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret,
            ]),
            new Response,
        );
        $code = json_decode((string) $response->getBody(), true)['device_code'];
        $parameters = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $code, 'client_id' => $client->getKey(), 'client_secret' => $client->plainSecret,
        ];

        foreach (['authorization_pending', 'slow_down'] as $error) {
            try {
                $this->exchange($parameters);
                $this->fail('An unapproved device must not receive tokens.');
            } catch (OAuthServerException $exception) {
                $this->assertSame($error, $exception->getErrorType());
            }

            // A different pooled session only sees the poll timestamp if the failed request committed it.
            [$polled] = parallel([static fn (): bool => DeviceCode::findOrFail($code)->last_polled_at !== null]);
            $this->assertTrue($polled);
        }

        $this->assertSame(0, Token::count());
        $this->assertSame(0, RefreshToken::count());
        $this->assertFalse(DeviceCode::findOrFail($code)->revoked);
    }

    /**
     * Approve a grant through the OAuth server and return its exchange parameters.
     *
     * @return array<string, mixed>
     */
    protected function grantRequest(Client $client, string $grant, ?Consent $consent = null): array
    {
        $servers = $this->app->make(ServerFactory::class);
        $server = $servers->authorizationServer();
        $credentials = ['client_id' => $client->getKey(), 'client_secret' => $client->plainSecret];

        if ($grant === 'device_code') {
            $response = $server->respondToDeviceAuthorizationRequest(
                (new ServerRequest('POST', 'https://example.test/oauth/device/code'))->withParsedBody($credentials),
                new Response,
            );
            $code = json_decode((string) $response->getBody(), true)['device_code'];
            TokenIssuance::run($servers, fn () => $server->completeDeviceAuthorizationRequest($code, '1', true), [
                'provider' => 'users', 'consent_id' => $consent?->id,
            ]);

            return [...$credentials, 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $code];
        }

        $authorization = $server->validateAuthorizationRequest(
            (new ServerRequest('GET', 'https://example.test/oauth/authorize'))->withQueryParams([
                'client_id' => $client->getKey(),
                'response_type' => 'code',
                'redirect_uri' => $redirect = $client->redirect_uris[0],
            ])
        );
        $authorization->setUser(new User('1'));
        $authorization->setAuthorizationApproved(true);
        $response = TokenIssuance::run(
            $servers,
            fn () => $server->completeAuthorizationRequest($authorization, new Response),
            ['provider' => 'users', 'consent_id' => $consent?->id],
        );
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $parameters);
        $request = [...$credentials, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirect, 'code' => $parameters['code']];

        return $grant === 'refresh_token'
            ? [...$credentials, 'grant_type' => 'refresh_token', 'refresh_token' => $this->exchange($request)['refresh_token']]
            : $request;
    }

    /**
     * Exchange a grant using the real server and repositories.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function exchange(array $parameters): array
    {
        $response = $this->app->make(ServerFactory::class)->authorizationServer()->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://example.test/oauth/token'))->withParsedBody($parameters),
            new Response,
        );

        return json_decode((string) $response->getBody(), true);
    }
}
