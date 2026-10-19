<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Hypervel\Http\Request;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\TestWith;

class AuthorizationCodeReplayTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register the authorization view and a protected resource.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::authorizationView(fn ($parameters) => $parameters);
        Route::get('/resource', fn (Request $request): string => (string) $request->user()->getAuthIdentifier())
            ->middleware('auth:passport');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testReusingACodeRevokesItsIssuedCredentials(bool $refresh): void
    {
        $client = ClientFactory::new()->create([
            'grant_types' => $refresh ? ['authorization_code', 'refresh_token'] : ['authorization_code'],
        ]);
        $parameters = $this->authorize($client);
        $tokens = $this->post('/oauth/token', $parameters)->assertOk()->json();
        $this->withToken($tokens['access_token'])->getJson('/resource')->assertOk();

        $this->post('/oauth/token', $parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->withToken($tokens['access_token'])->getJson('/resource')->assertUnauthorized();

        if ($refresh) {
            $this->post('/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $client->id,
                'client_secret' => $client->plainSecret,
                'refresh_token' => $tokens['refresh_token'],
            ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        }
    }

    #[TestWith(['client'])]
    #[TestWith(['redirect'])]
    #[TestWith(['verifier'])]
    #[TestWith(['missing-verifier'])]
    public function testUnprovenCodeReuseCannotRevokeCredentials(string $change): void
    {
        $client = ClientFactory::new()->create();
        $parameters = $this->authorize($client);
        $tokens = $this->post('/oauth/token', $parameters)->assertOk()->json();

        if ($change === 'client') {
            $other = ClientFactory::new()->create();
            $parameters['client_id'] = $other->id;
            $parameters['client_secret'] = $other->plainSecret;
        } elseif ($change === 'redirect') {
            $parameters['redirect_uri'] = 'https://other.example/callback';
        } elseif ($change === 'verifier') {
            $parameters['code_verifier'] = str_repeat('b', 43);
        } else {
            unset($parameters['code_verifier']);
        }

        $this->post('/oauth/token', $parameters)->assertBadRequest();
        $this->withToken($tokens['access_token'])->getJson('/resource')->assertOk();
        $this->assertNull(Passport::refreshTokenFamily()->newQuery()->sole()->revoked_at);
    }

    public function testCodeReplayRevokesRotatedTokensAfterPurgingExpiredRefreshLinks(): void
    {
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('PT5M'));
        $client = ClientFactory::new()->create();
        $parameters = $this->authorize($client);
        $original = $this->post('/oauth/token', $parameters)->assertOk()->json();
        $originalRefresh = Passport::refreshToken()->newQuery()->sole();
        Passport::refreshTokensExpireIn(new DateInterval('PT6M'));
        $rotated = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'refresh_token' => $original['refresh_token'],
        ])->assertOk()->json();
        $successor = Passport::refreshToken()->newQuery()->where('revoked', false)->sole();

        $this->travelTo($originalRefresh->expires_at->addSecond());
        $this->artisan('passport:purge', ['--expired' => true, '--hours' => 0])->assertSuccessful();
        $this->assertModelMissing($originalRefresh);
        $this->assertModelExists($successor);
        $this->withToken($rotated['access_token'])->getJson('/resource')->assertOk();

        $this->travelTo($successor->expires_at->addSecond());
        $this->artisan('passport:purge', ['--expired' => true, '--hours' => 0])->assertSuccessful();
        $this->assertModelMissing($originalRefresh);
        $this->assertModelExists($successor);
        $this->withToken($rotated['access_token'])->getJson('/resource')->assertOk();

        $this->post('/oauth/token', $parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->withToken($rotated['access_token'])->getJson('/resource')->assertUnauthorized();
        $this->assertTrue($successor->fresh()->revoked);

        $this->artisan('passport:purge', ['--revoked' => true])->assertSuccessful();
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_token_families', 0);
    }

    /**
     * Approve a real PKCE authorization and return its exchange parameters.
     *
     * @return array<string, string>
     */
    protected function authorize(Client $client): array
    {
        $verifier = str_repeat('a', 43);
        $this->actingAs(UserFactory::new()->create(), 'web');
        $authToken = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))->assertOk()->json('authToken');
        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $authToken])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

        return [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri' => $client->redirect_uris[0],
            'code' => $parameters['code'],
            'code_verifier' => $verifier,
        ];
    }
}
