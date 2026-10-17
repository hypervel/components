<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Http\Request;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthorizationCodeGrantWithPkceTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register the available scopes and authorization view.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::tokensCan([
            'create' => 'Create',
            'read' => 'Read',
            'update' => 'Update',
            'delete' => 'Delete',
        ]);

        Passport::authorizationView(fn ($params) => $params);
    }

    public function testIssueAccessToken(): void
    {
        $client = ClientFactory::new()->asPublic()->create();

        $codeVerifier = Str::random(128);
        $codeChallenge = strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_');

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'scope' => 'create read',
            'state' => $state = Str::random(40),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');

        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertOk();
        $json = $response->json();
        $response->assertSessionHas('passport.authorizations.' . $json['authToken']);
        $this->assertEqualsCanonicalizing(['client', 'user', 'scopes', 'permissions', 'resources', 'request', 'authToken', 'redirectUri'], array_keys($json));
        $this->assertSame($redirect, $json['redirectUri']);
        $this->assertSame(collect(Passport::scopesFor(['create', 'read']))->toArray(), $json['scopes']);

        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $json['authToken']]);
        $response->assertRedirect();
        $response->assertSessionMissing('passport.authorizations.' . $json['authToken']);

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertStringStartsWith($redirect . '?', $location);
        $this->assertSame($state, $params['state']);
        $this->assertArrayHasKey('code', $params);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'code' => $params['code'],
            'code_verifier' => Str::random(128),
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertFalse(AuthCode::sole()->revoked);
        $this->assertSame(0, Token::count());

        $response = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'code' => $params['code'],
            'code_verifier' => $codeVerifier,
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertEqualsWithDelta(31536000, $json['expires_in'], 2);

        $refreshToken = $json['refresh_token'];

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'code' => $params['code'],
            'code_verifier' => $codeVerifier,
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertTrue(AuthCode::sole()->revoked);
        $this->assertSame(1, Token::count());

        Route::get('/foo', fn (Request $request) => $request->user()->currentAccessToken()->toJson())
            ->middleware('auth:passport');

        $json = $this->withToken($json['access_token'], $json['token_type'])->get('/foo')->json();

        $this->assertSame($client->getKey(), $json['oauth_client_id']);
        $this->assertEquals($user->getAuthIdentifier(), $json['oauth_user_id']);
        $this->assertSame(['create', 'read'], $json['oauth_scopes']);

        $newToken = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->getKey(),
            'refresh_token' => $refreshToken,
            'scope' => 'create read',
        ])->assertOK()->json();

        $this->assertArrayHasKey('access_token', $newToken);
        $this->assertArrayHasKey('refresh_token', $newToken);
        $this->assertEqualsWithDelta(31536000, $newToken['expires_in'], 2);
        $this->assertSame('Bearer', $newToken['token_type']);
    }

    public function testRequireCodeChallenge(): void
    {
        $client = ClientFactory::new()->asPublic()->create();

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'state' => $state = Str::random(40),
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertRedirect();
        $this->assertStringStartsWith($redirect . '?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame($state, $params['state']);

        $this->assertSame('invalid_request', $params['error']);
        $this->assertSame('Code challenge must be provided for public clients', $params['hint']);
        $this->assertArrayHasKey('error_description', $params);

        $query .= '&code_challenge=foo&code_challenge_method=S256';
        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertRedirect();
        $this->assertStringStartsWith($redirect . '?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame($state, $params['state']);

        $this->assertSame('invalid_request', $params['error']);
        $this->assertSame('Code challenge must follow the specifications of RFC-7636.', $params['hint']);
        $this->assertArrayHasKey('error_description', $params);
    }

    #[DataProvider('invalidCodeChallengeMethods')]
    public function testInvalidCodeChallengeMethod(?string $method): void
    {
        $client = ClientFactory::new()->asPublic()->create();

        $codeVerifier = Str::random(128);
        $codeChallenge = strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_');

        $query = http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect = $client->redirect_uris[0],
            'response_type' => 'code',
            'state' => $state = Str::random(40),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $method,
        ]);

        $user = UserFactory::new()->create();
        $this->actingAs($user, 'web');
        $response = $this->get('/oauth/authorize?' . $query);

        $response->assertRedirect();
        $this->assertStringStartsWith($redirect . '?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame($state, $params['state']);

        $this->assertSame('invalid_request', $params['error']);
        $this->assertStringStartsWith('Code challenge method must be', $params['hint']);
        $this->assertArrayHasKey('error_description', $params);
    }

    /**
     * Provide challenges that do not select the required S256 method.
     */
    public static function invalidCodeChallengeMethods(): iterable
    {
        yield 'unknown method' => ['foo'];
        yield 'plain method' => ['plain'];
        yield 'missing method' => [null];
    }
}
