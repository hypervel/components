<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Hypervel\Http\Request;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;

class ClientCredentialsGrantTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Register the available scopes.
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
    }

    public function testIssueAccessToken(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->create();

        $json = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'scope' => 'create read delete',
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayNotHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertEqualsWithDelta(31536000, $json['expires_in'], 2);

        Route::get('/foo', fn (Request $request) => response('response'))
            ->middleware([EnsureClientIsResourceOwner::using(['create', 'delete'])]);

        $response = $this->withToken($json['access_token'], $json['token_type'])->get('/foo');
        $response->assertOk();
        $this->assertSame('response', $response->content());

        Route::get('/bar', fn (Request $request) => response('response'))
            ->middleware(CheckToken::using(['create', 'update']));

        $response = $this->withToken($json['access_token'], $json['token_type'])->get('/bar');
        $response->assertForbidden();

        Route::get('/machine', fn () => 'response')
            ->middleware(EnsureClientIsResourceOwner::using('update'));

        $this->withToken($json['access_token'])->get('/machine')->assertForbidden();
    }

    public function testIssueAccessTokenWithAllScopes(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->create();

        $json = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'scope' => '*',
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayNotHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
        $this->assertEqualsWithDelta(31536000, $json['expires_in'], 2);

        Route::get('/foo', fn (Request $request) => response('response'))
            ->middleware([EnsureClientIsResourceOwner::using(['create', 'delete'])]);

        $response = $this->withToken($json['access_token'], $json['token_type'])->get('/foo');
        $response->assertOk();

        Route::get('/bar', fn (Request $request) => response('response'))
            ->middleware(CheckToken::using(['create', 'delete']));

        $response = $this->withToken($json['access_token'], $json['token_type'])->get('/bar');
        $response->assertOk();
    }

    public function testPublicClient(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->asPublic()->create();

        $json = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
        ])->assertUnauthorized()->json();

        $this->assertSame('invalid_client', $json['error']);
        $this->assertSame('Client authentication failed', $json['error_description']);
    }

    public function testUnauthorizedClient(): void
    {
        $client = ClientFactory::new()->create();

        $json = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertBadRequest()->json();

        $this->assertSame('unauthorized_client', $json['error']);
        $this->assertSame(
            'The authenticated client is not authorized to use this authorization grant type.',
            $json['error_description']
        );
    }

    public function testCustomClientCredentialsTokenExpiration(): void
    {
        Passport::clientCredentialsTokensExpireIn(new DateInterval('P7D'));

        $client = ClientFactory::new()->asClientCredentials()->create();

        $json = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
        ])->assertOk()->json();

        // 7 days = 604800 seconds
        $this->assertEqualsWithDelta(604800, $json['expires_in'], 2);
    }
}
