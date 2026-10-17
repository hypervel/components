<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Firebase\JWT\JWT;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Events\AuthorizationRequestValidated;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PushedAuthorizationRequest;
use Hypervel\Passport\PushedAuthorizationRequestRepository;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;

class PushedAuthorizationRequestTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Configure the browser authorization responses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::tokensCan(['read' => 'Read', 'write' => 'Write']);
        Passport::authorizationView(static fn (array $parameters): array => $parameters);
        Route::get('/login', static fn (): string => 'Login')->name('login');
    }

    public function testPushFreezesParametersAndConsumptionIgnoresBrowserOverrides(): void
    {
        $client = ClientFactory::new()->create(['require_pushed_authorization_requests' => true]);
        $contexts = [];
        Event::listen(AuthorizationRequestValidated::class, static function (AuthorizationRequestValidated $event) use (&$contexts): void {
            $contexts[] = [$event->request->routeIs('passport.par'), $event->parameters];
        });
        $uri = $this->push($client, ['scope' => 'read', 'state' => ' original '])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('expires_in', 90)->json('request_uri');
        $stored = Passport::pushedAuthorizationRequest()->newQuery()->sole();
        $this->assertSame(hash('sha256', $uri), $stored->id);
        $this->assertArrayNotHasKey('client_secret', $stored->parameters);
        $this->assertTrue($contexts[0][0]);
        $this->assertArrayNotHasKey('client_secret', $contexts[0][1]);

        $this->actingAs(UserFactory::new()->create(), 'web');
        $url = '/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'request_uri' => $uri, 'scope' => 'write',
            'redirect_uri' => 'https://attacker.example/callback', 'state' => 'replaced',
        ]);
        $consent = $this->get($url)->assertOk()->assertJsonPath('scopes.0.id', 'read')->json('authToken');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 0);
        $this->assertFalse($contexts[1][0]);
        $this->assertSame($contexts[0][1], $contexts[1][1]);
        $location = $this->post('/oauth/authorize/consent', ['auth_token' => $consent])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $result);
        $this->assertSame(' original ', $result['state']);
        $this->get($url)->assertBadRequest()->assertJsonPath('error', 'invalid_request_uri')->assertHeaderMissing('Location');
    }

    public function testPublicClientsCanPushWithPkceAndGuestInteractionsResumeWithoutTheUri(): void
    {
        $client = ClientFactory::new()->create(['secret' => null, 'require_pushed_authorization_requests' => true]);
        $verifier = Str::random(64);
        $uri = $this->push($client, [
            'code_challenge' => JWT::urlsafeB64Encode(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ])->assertCreated()->json('request_uri');
        $resume = $this->get('/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'request_uri' => $uri]))
            ->assertRedirect()->headers->get('Location');
        $this->assertStringNotContainsString('request_uri', $resume);
        $this->get($resume)->assertRedirectToRoute('login');
        $this->actingAs(UserFactory::new()->create(), 'web');
        $token = $this->get($resume)->assertOk()->json('authToken');
        $location = $this->post('/oauth/authorize/consent', ['auth_token' => $token])->assertRedirect()->headers->get('Location');
        $this->assertDatabaseCount('oauth_auth_codes', 1);
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'code' => $parameters['code'], 'code_verifier' => $verifier,
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function testBasicAuthenticationDoesNotRequireABodyClientIdentifier(): void
    {
        $client = ClientFactory::new()->create();
        $this->withHeader('Authorization', 'Basic ' . base64_encode($client->id . ':' . $client->plainSecret))
            ->form(['response_type' => 'code'])->assertCreated();
        $this->assertSame($client->id, Passport::pushedAuthorizationRequest()->newQuery()->sole()->parameters['client_id']);
    }

    #[WithConfig('passport.issuer', 'https://auth.example')]
    #[WithConfig('passport.replay.store', 'database')]
    public function testSignedAssertionsAuthenticateOnceAndAreNeverSavedWithTheRequest(): void
    {
        $material = (new SigningKeyFactory)->definition();
        $client = ClientFactory::new()->create([
            'secret' => null, 'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'RS256',
            'jwks' => ['keys' => [ParsedKey::fromPublicKey($material['public_key'])->getJwk()]],
        ]);
        $parameters = [
            'response_type' => 'code',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => JWT::encode([
                'iss' => $client->id, 'sub' => $client->id, 'aud' => 'https://auth.example',
                'jti' => Str::random(40), 'iat' => time(), 'exp' => time() + 120,
            ], $material['private_key'], 'RS256'),
        ];

        $this->form($parameters)->assertCreated();
        $stored = Passport::pushedAuthorizationRequest()->newQuery()->sole()->parameters;
        $this->assertSame($client->id, $stored['client_id']);
        $this->assertArrayNotHasKey('client_assertion', $stored);
        $this->assertArrayNotHasKey('client_assertion_type', $stored);
        $this->form($parameters)->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 1);
    }

    #[TestWith(['client_secret', 'wrong', 'invalid_client'])]
    #[TestWith(['request_uri', 'urn:example:existing', 'invalid_request'])]
    #[TestWith(['scope', 'unknown', 'invalid_scope'])]
    #[TestWith(['scope', 'write', 'invalid_scope'])]
    #[TestWith(['response_mode', 'fragment', 'invalid_request'])]
    #[TestWith(['prompt', 'none login', 'invalid_request'])]
    public function testInvalidPushesReturnJsonWithoutRedirecting(string $name, string $value, string $error): void
    {
        $client = ClientFactory::new()->create(['scopes' => ['read']]);
        $response = $this->push($client, [$name => $value]);
        $this->assertGreaterThanOrEqual(400, $response->status());
        $response->assertJsonPath('error', $error)->assertHeaderMissing('Location');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 0);
    }

    public function testExtensionsRejectPushesBeforeStorageWithoutStartingInteractions(): void
    {
        Event::listen(AuthorizationRequestValidated::class, static function (AuthorizationRequestValidated $event): void {
            if ($event->request->routeIs('passport.par')) {
                throw OAuthServerException::invalidRequest('extension');
            }
        });
        $this->push(ClientFactory::new()->create())->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 0);
    }

    public function testWrongClientsCannotConsumeReferencesAndExpiryIsEnforced(): void
    {
        $client = ClientFactory::new()->create();
        $other = ClientFactory::new()->create();
        $uri = $this->push($client)->assertCreated()->json('request_uri');
        $this->get('/oauth/authorize?' . http_build_query(['client_id' => $other->id, 'request_uri' => $uri]))
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request_uri')->assertHeaderMissing('Location');
        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 1);
        $this->travel(91)->seconds();
        $this->get('/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'request_uri' => $uri]))
            ->assertBadRequest()->assertJsonPath('error', 'invalid_request_uri');
    }

    #[TestWith([[]])]
    #[TestWith([['read']])]
    public function testEffectiveDefaultScopesRemainFrozen(array $ceiling): void
    {
        Passport::defaultScopes(['read']);
        $client = ClientFactory::new()->create(['scopes' => $ceiling]);
        $uri = $this->push($client)->assertCreated()->json('request_uri');
        Passport::defaultScopes(['write']);
        $client->forceFill(['scopes' => ['read', 'write']])->save();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $response = $this->get('/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'request_uri' => $uri]))->assertOk();
        $this->assertSame($ceiling, array_column($response->json('scopes'), 'id'));
    }

    #[TestWith(['scopes', [], 'invalid_scope', true])]
    #[TestWith(['grant_types', [], 'unauthorized_client', true])]
    #[TestWith(['redirect_uris', ['https://other.example/callback'], 'invalid_client', false])]
    public function testConsumptionRechecksChangedClientPolicy(string $name, array $value, string $error, bool $redirect): void
    {
        $client = ClientFactory::new()->create();
        $uri = $this->push($client, ['scope' => 'read'])->assertCreated()->json('request_uri');
        $client->forceFill([$name => $value])->save();
        $response = $this->get('/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'request_uri' => $uri]));

        if ($redirect) {
            $response->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
            $this->assertSame($error, $parameters['error']);
        } else {
            $response->assertUnauthorized()->assertJsonPath('error', $error)->assertHeaderMissing('Location');
        }

        $this->assertDatabaseCount('oauth_pushed_authorization_requests', 0);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testRequiredParRejectsDirectRequestsAndPreviouslySavedDirectInteractions(): void
    {
        $client = ClientFactory::new()->create();
        $this->actingAs(UserFactory::new()->create(), 'web');
        $url = '/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'response_type' => 'code']);
        $token = $this->get($url)->assertOk()->json('authToken');
        $client->forceFill(['require_pushed_authorization_requests' => true])->save();

        foreach ([$url, route('passport.authorizations.resume', ['authorization' => $token])] as $request) {
            $location = $this->get($request)->assertRedirect()->headers->get('Location');
            parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
            $this->assertSame('invalid_request', $parameters['error']);
        }

        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testConsumptionRejectsAnApplicationTransactionWithoutDeletingTheReference(): void
    {
        $client = ClientFactory::new()->create();
        $uri = $this->push($client)->assertCreated()->json('request_uri');
        DB::beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot run inside an application transaction');
            $this->app->make(PushedAuthorizationRequestRepository::class)->consume($uri, $client->id);
        } finally {
            $this->assertDatabaseCount('oauth_pushed_authorization_requests', 1);
            DB::rollBack();
        }
    }

    public function testPushRequiresBoundedFormInput(): void
    {
        $this->postJson('/oauth/par', ['response_type' => 'code'])->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->form(['state' => str_repeat('a', RequestParameters::MAX_ENCODED_LENGTH)])
            ->assertStatus(413)->assertJsonPath('error', 'invalid_request')->assertHeaderMissing('Location');
    }

    public function testReplacementModelStoresPushedRequests(): void
    {
        Passport::usePushedAuthorizationRequestModel(CustomPushedAuthorizationRequest::class);
        $this->push(ClientFactory::new()->create())->assertCreated();
        $this->assertSame(CustomPushedAuthorizationRequest::class, Passport::pushedAuthorizationRequestModel());
        $this->assertInstanceOf(CustomPushedAuthorizationRequest::class, Passport::pushedAuthorizationRequest()->newQuery()->sole());
    }

    /**
     * Push a client's request with its configured credentials.
     */
    protected function push(Client $client, array $parameters = []): TestResponse
    {
        return $this->form([
            'client_id' => $client->id, 'response_type' => 'code',
            ...($client->plainSecret === null ? [] : ['client_secret' => $client->plainSecret]),
            ...$parameters,
        ]);
    }

    /**
     * Send the protocol's raw form encoding.
     */
    protected function form(array $parameters): TestResponse
    {
        return $this->call('POST', '/oauth/par', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]), RequestParameters::toQuery($parameters));
    }
}

class CustomPushedAuthorizationRequest extends PushedAuthorizationRequest
{
}
