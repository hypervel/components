<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passport\Bridge\AccessToken;
use Hypervel\Passport\Bridge\BearerTokenResponse;
use Hypervel\Passport\Bridge\RsaSigner;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Events\AccessTokenCreated;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Passport\Events\AuthorizationUserResolved;
use Hypervel\Passport\Events\RefreshTokenCreated;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\User;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse as LeagueBearerTokenResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

#[WithConfig('passport.issuer', 'https://identity.example.com')]
class TokenExtensionsTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected User $user;

    /**
     * Add application-owned authorization attributes to the credential tables.
     */
    protected function setUpInCoroutine(): void
    {
        $this->user = UserFactory::new()->create();

        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_device_codes', 'oauth_refresh_token_families'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->string('organization_id')->nullable();
                $table->string('nonce')->nullable();
                $table->unsignedBigInteger('auth_time')->nullable();
            });
        }

        Passport::tokensCan(['read' => 'Read', 'offline_access' => 'Renew access']);
        Passport::authorizationView(fn (array $parameters): array => $parameters);
        Passport::deviceAuthorizationView(fn (array $parameters): array => $parameters);
        Event::listen(AuthorizationUserResolved::class, static function (AuthorizationUserResolved $event): void {
            $event->attributes = [
                'organization_id' => 'organization-1',
                'nonce' => $event->parameters['nonce'] ?? null,
                'auth_time' => time(),
            ];
        });
        Passport::inheritGrantAttributes(['organization_id', 'nonce', 'auth_time']);
        Passport::accessTokenClaimsUsing(fn (Token $token): array => ['organization_id' => $token->organization_id]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAuthorizationAttributesReachTokensAndCustomResponsesAcrossRefreshes(bool $device): void
    {
        Passport::useAuthorizationServerResponseType(new ExtensionTokenResponse($this->app->make(ServerFactory::class)));
        $client = $device ? ClientFactory::new()->asDeviceCodeClient()->create() : ClientFactory::new()->create();
        $request = $this->approveGrant($client, $device);
        $grant = ($device ? Passport::deviceCode() : Passport::authCode())->newQuery()->firstOrFail();
        $this->assertSame('users', $grant->provider);
        $this->assertSame('original-nonce', $grant->nonce);
        $this->assertSame('organization-1', $grant->organization_id);
        $this->assertSame(['read', 'offline_access'], $grant->scopes);

        $grantReads = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$grantReads): void {
            if (str_starts_with($query->sql, 'select')) {
                foreach (['oauth_auth_codes', 'oauth_device_codes', 'oauth_refresh_tokens'] as $table) {
                    if (str_contains($query->sql, 'from "' . $table . '"')) {
                        $grantReads[] = $table;
                    }
                }
            }
        });

        $response = $this->post('/oauth/token', $request)->assertOk()->json();
        $this->assertSame('read offline_access', $response['scope']);
        $this->assertSame([$device ? 'oauth_device_codes' : 'oauth_auth_codes'], $grantReads);
        $parser = new Parser(new JoseEncoder);
        $accessToken = $parser->parse($response['access_token']);
        $idToken = $parser->parse($response['id_token']);
        $savedToken = Passport::token()->newQuery()->findOrFail($accessToken->claims()->get('jti'));

        $this->assertSame('organization-1', $accessToken->claims()->get('organization_id'));
        $this->assertSame('https://identity.example.com', $accessToken->claims()->get('iss'));
        $this->assertSame($accessToken->headers()->get('kid'), $idToken->headers()->get('kid'));
        $verificationKey = $this->app->make(ServerFactory::class)->entry()->verificationKeys[$idToken->headers()->get('kid')];
        $this->assertTrue((new RsaSigner)->verify($idToken->signature()->hash(), $idToken->payload(), $verificationKey));
        $this->assertSame('original-nonce', $idToken->claims()->get('nonce'));
        $this->assertSame($grant->auth_time, $idToken->claims()->get('auth_time'));
        $this->assertSame('users', $savedToken->provider);
        $this->assertSame($grant->auth_time, $savedToken->auth_time);
        $this->assertSame(rtrim(strtr(base64_encode(substr(hash('sha256', $response['access_token'], true), 0, 16)), '+/', '-_'), '='), $idToken->claims()->get('at_hash'));
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'access_token_id' => $savedToken->getKey(),
            'provider' => 'users',
            'organization_id' => 'organization-1',
            'nonce' => 'original-nonce',
            'auth_time' => $grant->auth_time,
        ]);
        $family = Passport::refreshTokenFamily()->newQuery()->firstOrFail();
        $this->assertSame('organization-1', $family->organization_id);

        $grantReads = [];
        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $response['refresh_token'],
        ])->assertOk()->json();
        $this->assertSame(['oauth_refresh_tokens'], $grantReads);
        $refreshedIdToken = $parser->parse($refreshed['id_token']);
        $this->assertSame($grant->auth_time, $refreshedIdToken->claims()->get('auth_time'));
        $this->assertFalse($refreshedIdToken->claims()->has('nonce'));
        $this->assertDatabaseCount('oauth_refresh_token_families', 1);
        $this->assertSame([$family->getKey()], Passport::refreshToken()->newQuery()->distinct()->pluck('family_id')->all());
    }

    public function testAutomaticApprovalCapturesTheCurrentAuthorizationAttributes(): void
    {
        $client = ClientFactory::new()->create();
        $this->post('/oauth/token', $this->approveGrant($client, false))->assertOk();

        $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'response_type' => 'code',
            'scope' => 'read',
            'nonce' => 'new-authorization',
        ]))->assertRedirect();

        $this->assertDatabaseHas('oauth_auth_codes', [
            'nonce' => 'new-authorization',
            'organization_id' => 'organization-1',
            'provider' => 'users',
            'user_id' => $this->user->getKey(),
            'revoked' => false,
        ]);
    }

    public function testNestedAndLaterPersonalAccessTokensDoNotInheritAuthorizationAttributes(): void
    {
        $client = ClientFactory::new()->create();
        ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $request = $this->approveGrant($client, false);
        $created = [];
        Token::created(static function (Token $token) use (&$created): void {
            $created[] = [
                $token->name,
                $token->organization_id,
                $token->nonce,
                $token->provider,
            ];
        });
        Event::listen(AccessTokenCreated::class, function (AccessTokenCreated $event) use ($client): void {
            if ($event->clientId === $client->getKey()) {
                $this->user->createToken('Listener', ['read']);
            }
        });

        $this->post('/oauth/token', $request)->assertOk();
        $this->user->createToken('Later', ['read']);

        // The creation event sees the name in the original insert, before any follow-up update.
        $this->assertSame([
            [null, 'organization-1', 'original-nonce', 'users'],
            ['Listener', null, null, 'users'],
            ['Later', null, null, 'users'],
        ], $created);
        $this->assertDatabaseCount('oauth_access_tokens', 3);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testRefreshPolicyCanSuppressRefreshTokensAtInitialExchange(bool $device): void
    {
        $observed = [];
        Passport::issueRefreshTokensUsing(function (Token $token) use (&$observed): bool {
            $observed[] = [$token->exists, $token->organization_id, $token->auth_time];

            return false;
        });
        $client = $device ? ClientFactory::new()->asDeviceCodeClient()->create() : ClientFactory::new()->create();
        $request = $this->approveGrant($client, $device);

        $this->post('/oauth/token', $request)->assertOk()->assertJsonMissingPath('refresh_token');

        $this->assertCount(1, $observed);
        $this->assertTrue($observed[0][0]);
        $this->assertSame('organization-1', $observed[0][1]);
        $this->assertIsInt($observed[0][2]);
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_token_families', 0);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPublicClientsCannotIssueOrRedeemRefreshTokensWithoutRotation(bool $existingToken): void
    {
        Passport::$revokeRefreshTokenAfterUse = $existingToken;
        $client = ClientFactory::new()->asDeviceCodeClient()->asPublic()->create();
        $request = $this->approveGrant($client, true);
        $response = $this->post('/oauth/token', $request)->assertOk();

        if (! $existingToken) {
            $response->assertJsonMissingPath('refresh_token');
            $this->assertDatabaseCount('oauth_refresh_tokens', 0);
            $this->assertDatabaseCount('oauth_refresh_token_families', 0);

            return;
        }

        Passport::$revokeRefreshTokenAfterUse = false;
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'refresh_token' => $response->json('refresh_token'),
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertFalse(Passport::refreshToken()->newQuery()->sole()->revoked);
        $this->assertNull(Passport::refreshTokenFamily()->newQuery()->sole()->revoked_at);
    }

    public function testRefreshPolicyCanEndRenewalUsingTheOriginalAuthorizationTime(): void
    {
        Passport::issueRefreshTokensUsing(fn (Token $token): bool => $token->auth_time > now()->subHour()->getTimestamp());
        $client = ClientFactory::new()->create();
        $response = $this->post('/oauth/token', $this->approveGrant($client, false))->assertOk()->json();
        $oldToken = Passport::token()->newQuery()->firstOrFail();
        $oldRefresh = Passport::refreshToken()->newQuery()->firstOrFail();
        $events = [];
        Event::listen([AccessTokenCreated::class, RefreshTokenCreated::class], function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $request = [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'refresh_token' => $response['refresh_token'],
        ];
        $this->travel(2)->hours();

        $this->post('/oauth/token', $request)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertFalse($oldToken->fresh()->revoked);
        $this->assertFalse($oldRefresh->fresh()->revoked);
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_token_families', 1);
        $this->assertSame([], $events);

        $this->travelBack();
        $this->post('/oauth/token', $request)->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        $this->assertTrue($oldRefresh->fresh()->revoked);
    }

    #[DataProvider('issuanceFailures')]
    public function testFailedIssuanceLeavesItsGrantAndExistingTokensUsable(string $grant, string $failure): void
    {
        Passport::$revokeRefreshTokenAfterUse = $grant !== 'non_rotating_refresh';
        $responseFailure = null;
        $responseType = new FailingExtensionTokenResponse(static function () use (&$responseFailure): void {
            if ($responseFailure !== null) {
                throw $responseFailure;
            }
        });
        Passport::useAuthorizationServerResponseType($responseType);
        $client = match ($grant) {
            'device_code' => ClientFactory::new()->asDeviceCodeClient()->create(),
            'client_credentials' => ClientFactory::new()->asClientCredentials()->create(),
            default => ClientFactory::new()->create(),
        };

        if ($grant === 'client_credentials') {
            $request = [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret,
                'scope' => 'read',
            ];
            $credential = null;
        } else {
            $request = $this->approveGrant($client, $grant === 'device_code');

            if (in_array($grant, ['refresh_token', 'non_rotating_refresh'], true)) {
                $initial = $this->post('/oauth/token', $request)->assertOk()->json();
                $request = [
                    'grant_type' => 'refresh_token',
                    'client_id' => $client->getKey(),
                    'client_secret' => $client->plainSecret,
                    'refresh_token' => $initial['refresh_token'],
                ];
                $credential = Passport::refreshToken()->newQuery()->firstOrFail();
            } else {
                $credential = ($grant === 'device_code' ? Passport::deviceCode() : Passport::authCode())
                    ->newQuery()->firstOrFail();
            }
        }

        $originalTokens = Passport::token()->newQuery()->get()->toArray();
        $originalRefreshTokens = Passport::refreshToken()->newQuery()->get()->toArray();
        $originalFamilies = Passport::refreshTokenFamily()->newQuery()->get()->toArray();
        $events = [];
        Event::listen([AccessTokenCreated::class, RefreshTokenCreated::class, AccessTokenRevoked::class], function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $exception = new RuntimeException('The response extension failed.');
        $cancelSave = $failure === 'persistence';

        if ($cancelSave) {
            ($grant === 'client_credentials' ? Passport::tokenModel() : Passport::refreshTokenModel())::creating(
                static function () use (&$cancelSave): bool {
                    return ! $cancelSave;
                }
            );
        } elseif ($failure === 'signing') {
            Passport::useAccessTokenEntity(FailingSigningAccessToken::class);
        } else {
            $responseFailure = $exception;
        }

        $this->withoutExceptionHandling();

        try {
            $this->post('/oauth/token', $request);
            $this->fail('The failed issuance must not return a token.');
        } catch (RuntimeException $caught) {
            if ($failure === 'response') {
                $this->assertSame($exception, $caught);
            } else {
                $this->assertSame($failure === 'signing' ? 'Signing failed.' : 'Credential persistence was canceled.', $caught->getMessage());
            }
        }

        $this->assertSame([], $events);
        $this->assertSame($originalTokens, Passport::token()->newQuery()->get()->toArray());
        $this->assertSame($originalRefreshTokens, Passport::refreshToken()->newQuery()->get()->toArray());
        $this->assertSame($originalFamilies, Passport::refreshTokenFamily()->newQuery()->get()->toArray());

        if ($credential !== null) {
            $this->assertFalse($credential->fresh()->revoked);
        }

        $cancelSave = false;
        $responseFailure = null;
        Passport::useAccessTokenEntity(AccessToken::class);

        $this->post('/oauth/token', $request)->assertOk()->assertJsonStructure(['access_token']);
        $this->assertDatabaseCount('oauth_access_tokens', count($originalTokens) + 1);
        $this->assertNotEmpty($events);
    }

    /**
     * Cover each grant's write path and failures before and after token persistence.
     */
    public static function issuanceFailures(): iterable
    {
        foreach (['authorization_code', 'device_code', 'refresh_token', 'non_rotating_refresh', 'client_credentials'] as $grant) {
            yield "{$grant}: response" => [$grant, 'response'];
            yield "{$grant}: persistence" => [$grant, 'persistence'];
        }

        yield 'authorization_code: signing' => ['authorization_code', 'signing'];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFailedPersonalAccessIssuanceLeavesNoTokenOrCreationEvent(bool $cancelSave): void
    {
        ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $events = [];
        Event::listen(AccessTokenCreated::class, function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $exception = new RuntimeException('The custom claims failed.');

        if ($cancelSave) {
            Token::creating(static function () use (&$cancelSave): bool {
                return ! $cancelSave;
            });
        } else {
            Passport::accessTokenClaimsUsing(static fn (): never => throw $exception);
        }

        try {
            $this->user->createToken('Example', ['read']);
            $this->fail('The failed issuance must not return a token.');
        } catch (RuntimeException $caught) {
            if ($cancelSave) {
                $this->assertSame('Credential persistence was canceled.', $caught->getMessage());
            } else {
                $this->assertSame($exception, $caught);
            }
        }

        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertSame([], $events);
        $cancelSave = false;
        Passport::accessTokenClaimsUsing(static fn (): array => []);

        $result = $this->user->createToken('Example', ['read']);
        $this->assertSame('Example', $result->token->name);
        $this->assertCount(1, $events);
    }

    #[TestWith(['authorization_code'])]
    #[TestWith(['device_code'])]
    #[TestWith(['refresh_token'])]
    public function testRedemptionRejectsAnApplicationTransactionWithoutConsumingTheGrant(string $grant): void
    {
        $client = $grant === 'device_code'
            ? ClientFactory::new()->asDeviceCodeClient()->create()
            : ClientFactory::new()->create();
        $request = $this->approveGrant($client, $grant === 'device_code');

        if ($grant === 'refresh_token') {
            $initial = $this->post('/oauth/token', $request)->assertOk()->json();
            $request = [
                'grant_type' => 'refresh_token',
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret,
                'refresh_token' => $initial['refresh_token'],
            ];
        }

        $this->withoutExceptionHandling();
        DB::beginTransaction();

        try {
            try {
                $this->post('/oauth/token', $request);
                $this->fail('An application transaction must prevent redemption.');
            } catch (LogicException $exception) {
                $this->assertSame('Passport token redemption cannot run inside an application transaction.', $exception->getMessage());
            }

            $this->assertSame(2, DB::transactionLevel());
            $this->assertDatabaseCount('oauth_access_tokens', $grant === 'refresh_token' ? 1 : 0);
            $this->assertDatabaseCount('oauth_refresh_tokens', $grant === 'refresh_token' ? 1 : 0);
        } finally {
            DB::rollBack();
        }

        $this->post('/oauth/token', $request)->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testNewTokensFollowTheirCallersCommitOrRollback(bool $personal, bool $commit): void
    {
        $client = $personal
            ? ClientFactory::new()->asPersonalAccessTokenClient()->create()
            : ClientFactory::new()->asClientCredentials()->create();
        $events = [];
        Event::listen(AccessTokenCreated::class, function (object $event) use (&$events): void {
            $events[] = $event;
        });
        DB::beginTransaction();

        try {
            if ($personal) {
                $this->user->createToken('Example', ['read']);
            } else {
                $this->post('/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $client->getKey(),
                    'client_secret' => $client->plainSecret,
                    'scope' => 'read',
                ])->assertOk();
            }

            $this->assertSame([], $events);
            $this->assertDatabaseCount('oauth_access_tokens', 1);
            $commit ? DB::commit() : DB::rollBack();
        } finally {
            DB::rollBack(1);
        }

        $this->assertDatabaseCount('oauth_access_tokens', $commit ? 1 : 0);
        $this->assertCount($commit ? 1 : 0, $events);
    }

    #[DataProvider('reservedClaims')]
    public function testReservedClaimsCannotBeOverriddenAndDoNotConsumeTheGrant(string $claim): void
    {
        Passport::accessTokenClaimsUsing(fn (): array => [$claim => 'replacement']);
        $client = ClientFactory::new()->create();
        $request = $this->approveGrant($client, false);
        $this->withoutExceptionHandling();

        try {
            $this->post('/oauth/token', $request);
            $this->fail('A reserved claim must reject issuance.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame("The access token claim [{$claim}] is reserved.", $exception->getMessage());
        }

        $this->assertFalse(Passport::authCode()->newQuery()->firstOrFail()->revoked);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_token_families', 0);

        Passport::accessTokenClaimsUsing(fn (): array => []);
        $this->post('/oauth/token', $request)->assertOk();
    }

    /**
     * Provide claims owned by the OAuth protocol and token validator.
     */
    public static function reservedClaims(): iterable
    {
        foreach (['iss', 'sub', 'aud', 'exp', 'iat', 'nbf', 'jti', 'scopes'] as $claim) {
            yield $claim => [$claim];
        }
    }

    /**
     * Approve an authorization and return its token-exchange parameters.
     *
     * @return array<string, mixed>
     */
    protected function approveGrant(Client $client, bool $device): array
    {
        $this->actingAs($this->user, 'web');
        $request = ['client_id' => $client->getKey(), 'client_secret' => $client->plainSecret];

        if ($device) {
            $code = $this->post('/oauth/device/code', [
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret,
                'scope' => 'read offline_access',
            ])->assertOk()->json();
            $authToken = $this->get('/oauth/device/authorize?' . http_build_query([
                'user_code' => $code['user_code'],
                'nonce' => 'original-nonce',
            ]))->assertOk()->json('authToken');
            $this->post('/oauth/device/authorize', ['auth_token' => $authToken])->assertRedirect();

            return [...$request, 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $code['device_code']];
        }

        $authToken = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'read offline_access',
            'nonce' => 'original-nonce',
        ]))->assertOk()->json('authToken');
        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $authToken])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

        return [...$request, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirect, 'code' => $parameters['code']];
    }
}

class ExtensionTokenResponse extends BearerTokenResponse
{
    /**
     * Use Passport's selected key and issuer for the additional token.
     */
    public function __construct(protected ServerFactory $servers)
    {
    }

    /**
     * Build an additional signed response from the saved authorization data.
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $token = $this->getToken();
        $lifetime = new DateInterval('PT10M');
        $key = $this->servers->signingKey($lifetime);
        $configuration = Configuration::forAsymmetricSigner(new RsaSigner, $key->getParsedKey(), InMemory::plainText('unused'));
        $now = new DateTimeImmutable;
        $builder = $configuration->builder()
            ->issuedBy($this->servers->settings()->issuer)
            ->permittedFor($token->client_id)
            ->relatedTo((string) $token->user_id)
            ->issuedAt($now)
            ->expiresAt($now->add($lifetime))
            ->withHeader('kid', $key->getKeyId())
            ->withClaim('auth_time', $token->auth_time)
            ->withClaim('at_hash', rtrim(strtr(base64_encode(substr(hash('sha256', $accessToken->toString(), true), 0, 16)), '+/', '-_'), '='));

        if (! $this->getGrant() instanceof RefreshToken) {
            $builder = $builder->withClaim('nonce', $token->nonce);
        }

        return [...parent::getExtraParams($accessToken), 'id_token' => $builder->getToken($configuration->signer(), $configuration->signingKey())->toString()];
    }
}

class FailingExtensionTokenResponse extends LeagueBearerTokenResponse
{
    /**
     * Share the failure control across League's response clones.
     */
    public function __construct(protected Closure $callback)
    {
    }

    /**
     * Simulate a response extension failing after both tokens have been saved.
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        ($this->callback)();

        return [];
    }
}

class FailingSigningAccessToken extends AccessToken
{
    /**
     * Simulate failure while producing the signed access token.
     */
    public function toString(): string
    {
        throw new RuntimeException('Signing failed.');
    }
}
