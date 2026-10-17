<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Events\ClientMetadataValidated;
use Hypervel\Passport\Exceptions\InvalidClientMetadataException;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\TestWith;

class ClientRegistrationTest extends PassportTestCase
{
    use WithHypervelMigrations;

    protected bool $registrationEnabled = true;

    /**
     * Enable registration before Passport builds its routes.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        if ($this->registrationEnabled) {
            Passport::enableDynamicClientRegistration();
        }
        $app->make('config')->set('passport.registration.scopes', ['read']);
    }

    /**
     * Restore the default disabled registration setting.
     */
    protected function disableRegistration(Application $app): void
    {
        $this->registrationEnabled = false;
    }

    /**
     * Register ordinary and explicit provisioning scopes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::tokensCan(['read' => 'Read', 'write' => 'Write', 'passport:register' => 'Register clients']);
    }

    #[DefineEnvironment('disableRegistration')]
    public function testRegistrationIsDisabledWithoutOptIn(): void
    {
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://client.example/callback']])->assertNotFound();
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testAnonymousRegistrationReturnsEffectiveMetadataAndOnlyTheNewSecret(bool $timestamps): void
    {
        Client::creating(static function (Client $client) use ($timestamps): void {
            $client->timestamps = $timestamps;
        });
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Calendar', 'redirect_uris' => ['https://client.example/callback'], 'scope' => 'read',
            'owner_id' => 123, 'provider' => 'staff', 'secret' => 'injected', 'unknown' => 'ignored',
            'dynamically_registered' => false,
            'require_pushed_authorization_requests' => true,
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('token_endpoint_auth_method', 'client_secret_basic')
            ->assertJsonPath('require_pushed_authorization_requests', true)
            ->assertJsonPath('grant_types', ['authorization_code'])
            ->assertJsonPath('response_types', ['code'])
            ->assertJsonPath('client_secret_expires_at', 0)
            ->assertJsonMissingPath('owner_id')->assertJsonMissingPath('provider')->assertJsonMissingPath('secret')
            ->assertJsonMissingPath('unknown')->assertJsonMissingPath('dynamically_registered');
        $client = Client::findOrFail($response->json('client_id'));

        $this->assertSame('Calendar', $client->name);
        $this->assertSame(['read'], $client->scopes);
        $this->assertNull($client->owner_id);
        $this->assertNull($client->provider);
        $this->assertTrue($client->dynamically_registered);
        $this->assertTrue($client->require_pushed_authorization_requests);
        $this->assertFalse($client->firstParty());
        $this->assertTrue($client->secretMatches($response->json('client_secret')));
        $this->assertNotSame($response->json('client_secret'), $client->secret);
        $this->assertNull($client->plainSecret);
        $this->assertIsInt($response->json('client_id_issued_at'));
    }

    #[TestWith(['none'])]
    #[TestWith(['private_key_jwt'])]
    public function testOpenRegistrationSupportsPublicAndAsymmetricClientsWithoutGeneratingSecrets(string $method): void
    {
        config(['passport.issuer' => 'https://issuer.example']);
        $metadata = ['redirect_uris' => ['https://client.example/callback'], 'token_endpoint_auth_method' => $method];

        if ($method === 'private_key_jwt') {
            $key = ParsedKey::fromPublicKey((new SigningKeyFactory)->definition()['public_key'])->getJwk();
            $metadata['jwks'] = ['keys' => [$key]];
        }

        $response = $this->postJson('/oauth/register', $metadata)->assertCreated()
            ->assertJsonMissingPath('client_secret')->assertJsonMissingPath('client_secret_expires_at');
        $client = Client::findOrFail($response->json('client_id'));
        $this->assertNull($client->secret);
        $this->assertSame([], $client->scopes);
        $this->assertSame($method === 'private_key_jwt', $client->confidential());
    }

    #[TestWith(['grant_types', ['client_credentials']])]
    #[TestWith(['grant_types', ['urn:ietf:params:oauth:grant-type:device_code']])]
    #[TestWith(['scope', 'write'])]
    #[TestWith(['scope', 'passport:register'])]
    #[TestWith(['scope', '*'])]
    public function testAnonymousRegistrationCannotGrantPrivilegedScopesOrGrants(string $name, mixed $value): void
    {
        // Even a mistakenly broad anonymous ceiling cannot expose the reserved provisioning scope.
        config(['passport.registration.scopes' => ['read', 'passport:register', '*']]);
        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://client.example/callback'], $name => $value,
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_client_metadata');
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function testBrowserAuthenticationDoesNotGrantProvisioningAuthority(): void
    {
        $this->actingAs(UserFactory::new()->create(), 'web')
            ->postJson('/oauth/register', ['grant_types' => ['client_credentials']])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_client_metadata');
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function testWildcardOnlyTokensDoNotGrantProvisioningAuthority(): void
    {
        $token = $this->machineToken('*');
        $this->withToken($token)->postJson('/oauth/register', ['grant_types' => ['client_credentials']])
            ->assertForbidden()->assertJsonPath('error', 'insufficient_scope')
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="passport:register"');
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testProvisioningTokensMustIncludeTheRegistrationAudience(bool $allowed): void
    {
        $resource = $allowed ? route('passport.clients.register') : 'https://other.example/api';
        Passport::tokensCanAccess([$resource => 'Resource']);
        $client = ClientFactory::new()->asClientCredentials()->create();
        $token = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'scope' => 'passport:register', 'resource' => $resource,
        ])->assertOk()->json('access_token');

        $response = $this->withToken($token)->postJson('/oauth/register', ['grant_types' => ['client_credentials']]);

        if ($allowed) {
            $response->assertCreated();
        } else {
            $response->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
        }

        $this->assertDatabaseCount('oauth_clients', $allowed ? 2 : 1);
    }

    public function testClientTestingHelperCanAuthorizeRegistration(): void
    {
        Passport::actingAsClient(ClientFactory::new()->asClientCredentials()->create(), ['passport:register']);

        $this->withToken('test-token')->postJson('/oauth/register', ['grant_types' => ['client_credentials']])
            ->assertCreated();
        $this->assertDatabaseCount('oauth_clients', 2);
    }

    #[TestWith(['Bearer invalid', 'invalid_token'])]
    #[TestWith(['Basic Y2xpZW50OnNlY3JldA==', null])]
    public function testInvalidProvisioningCredentialsReturnBearerChallenges(string $authorization, ?string $error): void
    {
        $response = $this->withHeader('Authorization', $authorization)->postJson('/oauth/register', [
            'redirect_uris' => ['https://client.example/callback'],
        ])->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('WWW-Authenticate', $error === null ? 'Bearer' : 'Bearer error="invalid_token"');

        if ($error === null) {
            $response->assertJsonMissingPath('error');
        } else {
            $response->assertJsonPath('error', $error);
        }

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testProvisioningTokensRequireAnActiveAllowedClient(bool $deniedByPolicy): void
    {
        $token = $this->machineToken('passport:register');

        if ($deniedByPolicy) {
            Passport::authenticateClientsUsing(static fn (Client $client): bool => false);
        } else {
            Client::sole()->revoke();
        }

        $this->withToken($token)->postJson('/oauth/register', ['grant_types' => ['client_credentials']])
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_token')
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public function testMachineProvisioningCreatesAnUnownedClientAndHonorsItsAuthenticationMethod(): void
    {
        $token = $this->machineToken('passport:register');
        $response = $this->withToken($token)->postJson('/oauth/register', [
            'client_name' => 'Worker', 'grant_types' => ['client_credentials'], 'scope' => 'read',
            'token_endpoint_auth_method' => 'client_secret_post',
        ])->assertCreated();
        $client = Client::findOrFail($response->json('client_id'));
        $this->assertNull($client->owner_id);

        $this->assertTrue($client->dynamically_registered);
        $this->assertFalse($client->firstParty());

        $this->withoutToken()->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->getKey(),
            'client_secret' => $response->json('client_secret'), 'scope' => 'read',
        ])->assertOk()->assertJsonPath('scope', 'read');

        $this->withBasicAuth($client->getKey(), $response->json('client_secret'))->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'scope' => 'read',
        ])->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
    }

    public function testUserProvisioningUsesTheTokensOwnerAndRejectsADeletedOwner(): void
    {
        Passport::useClientModel(GuardedRegistrationClient::class);
        ClientFactory::new()->asPersonalAccessTokenClient()->create();
        $owner = UserFactory::new()->create();
        $token = $owner->createToken('registration', ['passport:register'])->accessToken;
        $response = $this->withToken($token)->postJson('/oauth/register', [
            'grant_types' => ['client_credentials'], 'owner_id' => 999,
        ])->assertCreated();
        $client = Client::findOrFail($response->json('client_id'));
        $this->assertTrue($client->owner->is($owner));
        $this->assertTrue($client->dynamically_registered);
        $this->assertFalse($client->firstParty());

        $owner->delete();
        $this->postJson('/oauth/register', ['grant_types' => ['client_credentials']])
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_token')
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        $this->assertDatabaseCount('oauth_clients', 2);
    }

    public function testExtensionsArePersistedAndReturnedAndMayRejectMetadata(): void
    {
        Schema::table('oauth_clients', static fn (Blueprint $table) => $table->string('display_color')->nullable());
        Event::listen(ClientMetadataValidated::class, static function (ClientMetadataValidated $event): void {
            if (($event->submitted['display_color'] ?? null) !== 'blue') {
                throw InvalidClientMetadataException::invalidClientMetadata('The display color must be blue.');
            }

            $event->extensions['display_color'] = 'blue';
        });

        try {
            $response = $this->postJson('/oauth/register', [
                'redirect_uris' => ['https://client.example/callback'], 'display_color' => 'blue',
            ])->assertCreated()->assertJsonPath('display_color', 'blue');
            $this->assertDatabaseHas('oauth_clients', ['id' => $response->json('client_id'), 'display_color' => 'blue']);
            $this->postJson('/oauth/register', [
                'redirect_uris' => ['https://client.example/callback'], 'display_color' => 'red',
            ])->assertBadRequest()->assertJsonPath('error', 'invalid_client_metadata');
            $this->assertDatabaseCount('oauth_clients', 1);
        } finally {
            Schema::table('oauth_clients', static fn (Blueprint $table) => $table->dropColumn('display_color'));
        }
    }

    /**
     * Issue a real client-credentials token for provisioning tests.
     */
    protected function machineToken(string $scope): string
    {
        $client = ClientFactory::new()->asClientCredentials()->create();

        return $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret, 'scope' => $scope,
        ])->assertOk()->json('access_token');
    }
}

class GuardedRegistrationClient extends Client
{
    protected array $guarded = ['*'];
}
