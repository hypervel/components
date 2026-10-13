<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\Events\AccessTokenCreated;
use Hypervel\Passport\Events\RefreshTokenCreated;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\RefreshTokenFamily;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Coroutine\parallel;

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
        ] as $migration) {
            (require dirname(__DIR__, 4) . '/src/passport/database/migrations/' . $migration)->up();
        }
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
    #[TestWith(['authorization_code', true])]
    #[TestWith(['device_code', false])]
    #[TestWith(['device_code', true])]
    public function testRefreshIdentifierRetriesLeaveOneFamilyAndExhaustionRollsBackTheGrant(string $grant, bool $exhausted): void
    {
        $client = $grant === 'device_code'
            ? ClientFactory::new()->asDeviceCodeClient()->create()
            : ClientFactory::new()->create();
        $request = $this->grantRequest($client, $grant);
        $credential = $grant === 'device_code' ? DeviceCode::sole() : AuthCode::sole();
        $existingFamily = RefreshTokenFamily::create();
        RefreshToken::forceCreate([
            'id' => 'collision', 'access_token_id' => 'existing',
            'family_id' => $existingFamily->id, 'revoked' => false,
        ]);
        $attempts = 0;
        RefreshToken::creating(static function (RefreshToken $token) use (&$attempts, $exhausted): void {
            if (++$attempts === 1 || $exhausted) {
                $token->id = 'collision';
            }
        });
        $events = [];
        Event::listen([AccessTokenCreated::class, RefreshTokenCreated::class], static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $response = $this->exchange($request);
            $this->assertFalse($exhausted);
            $this->assertArrayHasKey('refresh_token', $response);
        } catch (UniqueTokenIdentifierConstraintViolationException) {
            $this->assertTrue($exhausted);
        }

        $this->assertSame($exhausted ? 10 : 2, $attempts);
        $this->assertSame(! $exhausted, $credential->fresh()->revoked);
        $this->assertSame($exhausted ? 0 : 1, Token::count());
        $this->assertSame($exhausted ? 1 : 2, RefreshToken::count());
        $this->assertSame($exhausted ? 1 : 2, RefreshTokenFamily::count());
        $this->assertCount($exhausted ? 0 : 2, $events);
    }

    public function testDeviceUserCodeCollisionIsRetriedInsideTheIssuanceTransaction(): void
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
        $request = $this->grantRequest($client, 'device_code');
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
    protected function grantRequest(Client $client, string $grant): array
    {
        $servers = $this->app->make(ServerFactory::class);
        $server = $servers->authorizationServer();
        $credentials = ['client_id' => $client->getKey(), 'client_secret' => $client->plainSecret];

        if ($grant === 'device_code') {
            $response = $server->respondToDeviceAuthorizationRequest(
                (new ServerRequest('POST', 'https://example.test/oauth/device/code'))->withParsedBody([
                    'client_id' => $client->getKey(),
                ]),
                new Response,
            );
            $code = json_decode((string) $response->getBody(), true)['device_code'];
            TokenIssuance::run($servers, fn () => $server->completeDeviceAuthorizationRequest($code, '1', true), ['provider' => 'users']);

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
            ['provider' => 'users'],
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
