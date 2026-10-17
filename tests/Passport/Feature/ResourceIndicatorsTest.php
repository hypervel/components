<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Client;
use Hypervel\Passport\Consent;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\Events\AuthorizationApproved;
use Hypervel\Passport\Http\Middleware\CheckTokenAudience;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\TransientToken;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\TestWith;

class ResourceIndicatorsTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected const string FIRST = 'https://api.example/first';

    protected const string SECOND = 'https://api.example/second';

    /**
     * Configure resources and real consent responses.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Passport::tokensCan(['read' => 'Read', 'write' => 'Write']);
        Passport::tokensCanAccess([self::FIRST => 'First API', self::SECOND => 'Second API']);
        Passport::authorizationView(static fn (array $parameters): array => $parameters);
        Passport::deviceAuthorizationView(static fn (array $parameters): array => $parameters);
    }

    /**
     * Authenticate the approving user on the test's database connection.
     */
    protected function setUpInCoroutine(): void
    {
        $this->actingAs(UserFactory::new()->create(), 'web');
    }

    #[TestWith([['https://api.example/first'], 'write'])]
    #[TestWith([['https://api.example/first', 'https://api.example/second'], ''])]
    #[TestWith([null, ''])]
    public function testResourcePairsSurviveCodeExchangeAndRefresh(?array $selected, string $scope): void
    {
        Route::get('/resource-token', static fn (): string => 'first')->middleware([
            'auth:passport', CheckTokenAudience::using(self::FIRST),
        ]);
        $client = ClientFactory::new()->create();
        $presentation = $this->presentation($client, [self::FIRST, self::SECOND]);
        $this->assertSame([
            ['id' => self::FIRST, 'description' => 'First API'],
            ['id' => self::SECOND, 'description' => 'Second API'],
        ], $presentation['resources']);

        $permissions = [self::FIRST => ['write'], self::SECOND => ['read']];
        $observedScopes = null;
        Event::listen(AuthorizationApproved::class, static function (AuthorizationApproved $event) use (&$observedScopes): void {
            $observedScopes = $event->scopes;
            $event->attributes['resources'] = ['https://unapproved.example' => ['write']];
            $event->attributes['audience'] = ['https://unapproved.example'];
        });
        $code = $this->approve($presentation['authToken'], ['permissions' => ['resources' => $permissions]]);
        $this->assertSame(['read', 'write'], $observedScopes);
        $this->assertSame($permissions, AuthCode::sole()->resources);
        $parameters = [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'redirect_uri' => $client->redirect_uris[0], 'code' => $code,
        ];

        $this->post('/oauth/token', [...$parameters, 'resource' => ['https://unapproved.example']])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_target');
        $this->assertFalse(AuthCode::sole()->revoked);

        $tokens = $this->call('POST', '/oauth/token', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: RequestParameters::toQuery([
            ...$parameters, ...($selected === null ? [] : ['resource' => $selected]),
        ]))->assertOk()->assertJsonPath('scope', $scope)->json();
        $claims = $this->app->make(ServerFactory::class)->tokenValidator()->validateToken($tokens['access_token'])->claims();
        $this->assertSame($client->id, $claims->get('client_id'));
        $this->assertSame($selected ?? [self::FIRST, self::SECOND], $claims->get('aud'));
        $this->assertSame($permissions, Passport::refreshToken()->sole()->resources);
        $this->getJson('/resource-token', ['Authorization' => 'Bearer ' . $tokens['access_token']])->assertOk();

        $next = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'refresh_token' => $tokens['refresh_token'], 'resource' => self::SECOND,
        ])->assertOk()->assertJsonPath('scope', 'read')->json();
        $this->getJson('/resource-token', ['Authorization' => 'Bearer ' . $tokens['access_token']])->assertUnauthorized();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'refresh_token' => $next['refresh_token'], 'resource' => self::FIRST,
        ])->assertOk()->assertJsonPath('scope', 'write');
    }

    public function testUnapprovedScopesAndZeroScopeTargetsAreNotRemembered(): void
    {
        $client = ClientFactory::new()->create();
        $presentation = $this->presentation($client, [self::FIRST, self::SECOND]);
        $this->approve($presentation['authToken'], ['permissions' => ['resources' => [self::FIRST => ['read']]]]);
        $this->assertSame(['read'], AuthCode::sole()->scopes);
        $consent = Consent::sole();
        $this->assertSame(['resources' => [self::FIRST => ['read']]], $consent->permissions);
        $this->assertFalse($consent->covers([], ['resources' => [self::SECOND => []]]));

        $presentation = $this->presentation($client, [self::SECOND], '');
        $this->approve($presentation['authToken']);
        $this->assertTrue($consent->fresh()->covers([], ['resources' => [self::SECOND => []]]));
    }

    public function testRemovingEveryTargetDeniesAuthorization(): void
    {
        $client = ClientFactory::new()->create();
        $presentation = $this->presentation($client, [self::FIRST]);
        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $presentation['authToken'], 'permissions' => []])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('access_denied', $parameters['error']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertDatabaseCount('oauth_consents', 0);
    }

    public function testUnknownBrowserResourceReturnsAnErrorToTheValidatedCallback(): void
    {
        $client = ClientFactory::new()->create();
        $response = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code', 'resource' => 'https://unknown.example/api',
        ]))->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith($client->redirect_uris[0] . '?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);
        $this->assertSame('invalid_target', $parameters['error']);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function testResourceOnlyConsentDoesNotApproveTheDefaultAudience(): void
    {
        $client = ClientFactory::new()->create();
        $this->approve($this->presentation($client, [self::FIRST])['authToken']);
        $silentRequest = '/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code', 'scope' => '', 'prompt' => 'none',
        ]);

        $response = $this->get($silentRequest)->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('consent_required', $parameters['error'] ?? null);
        $this->assertDatabaseCount('oauth_auth_codes', 1);
        $this->assertNull(Consent::sole()->scopes);

        $this->approve($this->presentation($client, null, '')['authToken']);
        $this->approve($this->presentation($client, [self::SECOND])['authToken']);
        $this->assertSame([], Consent::sole()->scopes);
        $response = $this->get($silentRequest)->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertArrayHasKey('code', $parameters);
        $this->assertDatabaseCount('oauth_auth_codes', 4);
    }

    public function testResourceLessAuthorizationCannotAcquireATarget(): void
    {
        $client = ClientFactory::new()->create();
        $code = $this->approve($this->presentation($client, null)['authToken']);
        $parameters = [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'redirect_uri' => $client->redirect_uris[0], 'code' => $code,
        ];
        $this->post('/oauth/token', [...$parameters, 'resource' => self::FIRST])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_target');
        $tokens = $this->post('/oauth/token', $parameters)->assertOk()->json();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'refresh_token' => $tokens['refresh_token'], 'resource' => self::FIRST,
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_target');
        $this->assertFalse(Passport::refreshToken()->sole()->revoked);
    }

    #[WithConfig('passport.cache', ['enabled' => true, 'store' => 'file', 'ttl' => 300, 'prefix' => 'passport'])]
    public function testMachineTokensEnforceTheirAudienceWithoutChangingClientIdentity(): void
    {
        $client = ClientFactory::new()->asClientCredentials()->create();
        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'scope' => 'read', 'resource' => self::FIRST,
        ])->assertOk()->assertJsonPath('scope', 'read')->json();
        Route::get('/first', static fn (): string => 'first')->middleware(CheckTokenAudience::using(self::FIRST));
        Route::get('/second', static fn (): string => 'second')->middleware(CheckTokenAudience::using(self::SECOND));
        Route::get('/either', static fn (): string => 'either')->middleware(CheckTokenAudience::using(self::SECOND, self::FIRST));
        Route::get('/both', static fn (): string => 'both')->middleware([
            CheckTokenAudience::using(self::FIRST), CheckTokenAudience::using(self::SECOND),
        ]);
        $this->withToken($tokens['access_token'])->getJson('/first')->assertOk();
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            ++$queries;
        });
        $this->withToken($tokens['access_token'])->getJson('/first')->assertOk();
        $this->withToken($tokens['access_token'])->getJson('/second')->assertUnauthorized();
        $this->withToken($tokens['access_token'])->getJson('/either')->assertOk();
        $this->withToken($tokens['access_token'])->getJson('/both')->assertUnauthorized();
        $this->assertSame(0, $queries);
        $claims = $this->app->make(ServerFactory::class)->tokenValidator()->validateToken($tokens['access_token'])->claims();
        $this->assertSame($client->id, $claims->get('client_id'));
        $this->assertSame([self::FIRST], $claims->get('aud'));
    }

    public function testTransientBrowserCredentialsCannotSatisfyAnAudience(): void
    {
        auth('web')->user()->withAccessToken(new TransientToken);
        Route::get('/audience', static fn (): string => 'protected')->middleware(CheckTokenAudience::using(self::FIRST));
        $this->getJson('/audience')->assertUnauthorized();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTestingHelpersRespectResourceAudiences(bool $client): void
    {
        Route::get('/first', static fn (): string => 'first')->middleware(CheckTokenAudience::using(self::FIRST));
        Route::get('/second', static fn (): string => 'second')->middleware(CheckTokenAudience::using(self::SECOND));

        if ($client) {
            Passport::actingAsClient(ClientFactory::new()->create(), resources: [self::FIRST]);
        } else {
            Passport::actingAs(auth('web')->user(), resources: [self::FIRST]);
        }

        $this->getJson('/first')->assertOk();
        $this->getJson('/second')->assertUnauthorized();
    }

    public function testAudienceMiddlewarePreservesCommasAndPercentEscapes(): void
    {
        $audiences = [
            'comma' => 'https://api.example/reports?fields=id,name',
            'escaped' => 'https://api.example/reports?fields=id%2Cname',
        ];
        $client = new Client(['id' => 'test-client']);

        foreach ($audiences as $path => $audience) {
            Route::get('/' . $path, static fn (): string => 'allowed')->middleware(CheckTokenAudience::using($audience));
        }

        foreach ($audiences as $path => $audience) {
            Passport::actingAsClient($client, resources: [$audience]);
            $this->getJson('/' . $path)->assertOk();
            $this->getJson('/' . ($path === 'comma' ? 'escaped' : 'comma'))->assertUnauthorized();
        }

        Passport::actingAsClient($client, resources: ['https://api.example/reports?fields=id']);
        $this->getJson('/comma')->assertUnauthorized();
    }

    public function testDeviceResourcesComeFromInitiationAndRespectPartialApproval(): void
    {
        $client = ClientFactory::new()->asDeviceCodeClient()->create();
        $device = $this->call('POST', '/oauth/device/code', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: RequestParameters::toQuery([
            'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => 'read write', 'resource' => [self::FIRST, self::SECOND],
        ]))->assertOk()->json();
        $this->assertSame([self::FIRST => ['read', 'write'], self::SECOND => ['read', 'write']], DeviceCode::sole()->resources);
        $presentation = $this->get('/oauth/device/authorize?' . http_build_query([
            'user_code' => $device['user_code'], 'resource' => 'https://unapproved.example',
        ]))->assertOk()->json();
        $this->assertCount(2, $presentation['resources']);
        $this->post('/oauth/device/authorize', [
            'auth_token' => $presentation['authToken'], 'permissions' => ['resources' => [self::FIRST => ['read']]],
        ])->assertRedirect();
        $this->assertSame([self::FIRST => ['read']], DeviceCode::sole()->resources);
        $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'device_code' => $device['device_code'], 'resource' => self::SECOND,
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_target');
        $this->post('/oauth/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'device_code' => $device['device_code'], 'resource' => self::FIRST,
        ])->assertOk()->assertJsonPath('scope', 'read');
    }

    /**
     * Present a request with exact repeated resource parameters.
     */
    protected function presentation(Client $client, ?array $resources, string $scope = 'read write'): array
    {
        return $this->get('/oauth/authorize?' . RequestParameters::toQuery([
            'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0],
            'response_type' => 'code', 'scope' => $scope, 'prompt' => 'consent',
            ...($resources === null ? [] : ['resource' => $resources]),
        ]))->assertOk()->json();
    }

    /**
     * Approve one presentation and retrieve its authorization code.
     */
    protected function approve(string $authToken, array $selection = []): string
    {
        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $authToken, ...$selection])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);

        return $parameters['code'];
    }
}
