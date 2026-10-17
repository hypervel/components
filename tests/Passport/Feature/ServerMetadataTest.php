<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Request;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\ServerSettings;
use Hypervel\Support\Facades\URL;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;

#[WithConfig('passport.issuer', 'https://auth.example')]
class ServerMetadataTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testDiscoveryPublishesOnlySupportedCapabilitiesAndConditionalResponses(): void
    {
        $response = $this->get('https://auth.example/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', 'https://auth.example')
            ->assertJsonPath('authorization_endpoint', 'https://auth.example/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://auth.example/oauth/token')
            ->assertJsonPath('revocation_endpoint', 'https://auth.example/oauth/revoke')
            ->assertJsonPath('revocation_endpoint_auth_methods_supported', ['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'])
            ->assertJsonPath('introspection_endpoint', 'https://auth.example/oauth/introspect')
            ->assertJsonPath('introspection_endpoint_auth_methods_supported', ['client_secret_basic', 'client_secret_post', 'private_key_jwt'])
            ->assertJsonPath('jwks_uri', 'https://auth.example/oauth/jwks')
            ->assertJsonPath('device_authorization_endpoint', 'https://auth.example/oauth/device/code')
            ->assertJsonPath('pushed_authorization_request_endpoint', 'https://auth.example/oauth/par')
            ->assertJsonPath('require_pushed_authorization_requests', false)
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('response_modes_supported', ['query', 'form_post'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code'])
            ->assertJsonPath('token_endpoint_auth_methods_supported', ['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'])
            ->assertJsonPath('token_endpoint_auth_signing_alg_values_supported', ['RS256', 'ES256'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('authorization_response_iss_parameter_supported', true)
            ->assertJsonMissingPath('scopes_supported')->assertJsonMissingPath('registration_endpoint')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');

        $this->withHeader('If-None-Match', $response->headers->get('ETag'))
            ->get('https://auth.example/.well-known/oauth-authorization-server')->assertStatus(304)->assertContent('');
    }

    #[DefineEnvironment('configureOptionalFeatures')]
    public function testDiscoveryReflectsRegistrationDeviceAndScopeConfiguration(): void
    {
        Passport::tokensCan(['read' => 'Read']);
        $this->get('https://auth.example/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('registration_endpoint', 'https://auth.example/oauth/register')
            ->assertJsonMissingPath('device_authorization_endpoint')
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token', 'client_credentials'])
            ->assertJsonPath('scopes_supported', ['read']);
    }

    /**
     * Configure feature flags before Passport registers routes.
     */
    protected function configureOptionalFeatures(Application $app): void
    {
        Passport::enableDynamicClientRegistration();
        Passport::$deviceCodeGrantEnabled = false;
    }

    #[WithConfig('passport.issuer', null)]
    public function testMissingIssuerDisablesDiscoveryWithoutDisablingPublicKeys(): void
    {
        $this->get('https://auth.example/.well-known/oauth-authorization-server')->assertNotFound();
        $response = $this->get('https://auth.example/oauth/jwks')->assertOk()
            ->assertExactJson($this->app->make(ServerFactory::class)->jwks())
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))
            ->get('https://auth.example/oauth/jwks')->assertStatus(304);
    }

    #[TestWith(['https://other.example/.well-known/oauth-authorization-server'])]
    #[TestWith(['https://auth.example/.well-known/oauth-authorization-server/other'])]
    #[TestWith(['http://auth.example/.well-known/oauth-authorization-server'])]
    public function testDiscoveryRejectsTheWrongLocation(string $url): void
    {
        $this->get($url)->assertNotFound();
    }

    #[TestWith(['https://auth.example/issuer/', 'https://auth.example/.well-known/oauth-authorization-server/issuer'])]
    #[TestWith(['HTTPS://Auth.Example:443/Tenant%7Eone/', 'https://auth.example/.well-known/oauth-authorization-server/Tenant%7Eone'])]
    #[TestWith(['https://auth.example:8443/', 'https://auth.example:8443/.well-known/oauth-authorization-server'])]
    public function testDiscoveryAndAuthorizationPreserveTheConfiguredIssuer(string $issuer, string $location): void
    {
        config(['passport.issuer' => $issuer]);
        $this->get($location)->assertOk()->assertJsonPath('issuer', $issuer);

        Passport::authorizationView(static fn (array $parameters): array => $parameters);
        $this->actingAs(UserFactory::new()->create(), 'web');
        $client = ClientFactory::new()->create();
        $consent = $this->get('/oauth/authorize?' . http_build_query(['client_id' => $client->id, 'response_type' => 'code']))
            ->assertOk()->json('authToken');
        $response = $this->post('/oauth/authorize/consent', ['auth_token' => $consent])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame($issuer, $parameters['iss']);
    }

    #[WithConfig('passport.path', 'servers/{server}/oauth')]
    #[WithConfig('passport.middleware', [MetadataRouteDefaults::class])]
    public function testDiscoveryUsesTrustedProxyPrefixesAndNamedRouteDefaults(): void
    {
        TrustProxies::at('*');
        $this->withHeaders([
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'auth.example', 'X-Forwarded-Prefix' => '/gateway',
        ])->get('http://internal/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('issuer', 'https://auth.example')
            ->assertJsonPath('token_endpoint', 'https://auth.example/gateway/servers/example/oauth/token')
            ->assertJsonPath('jwks_uri', 'https://auth.example/gateway/servers/example/oauth/jwks');
    }

    public function testChangedSettingsPublishNewKeysWithANewEtagAndTheirOwnCacheLifetime(): void
    {
        $servers = $this->app->make(ServerFactory::class);
        $keys = $servers->fileKeys();
        $material = (new KeyGenerator)->generate(2048);
        $settings = [
            'first' => new ServerSettings($keys, issuer: 'https://auth.example', metadataCacheLifetime: 30),
            'second' => new ServerSettings([...$keys, new ServerKey($material['public'])], issuer: 'https://auth.example'),
        ];
        $revision = 'first';
        $resolver = m::mock(ServerSettingsResolver::class);
        $resolver->shouldReceive('current')->andReturnUsing(static function () use (&$revision): string {
            return $revision;
        });
        $resolver->shouldReceive('resolve')->with('first')->once()->andReturn($settings['first']);
        $resolver->shouldReceive('resolve')->with('second')->once()->andReturn($settings['second']);
        $this->app->instance(ServerSettingsResolver::class, $resolver);

        $first = $this->get('https://auth.example/oauth/jwks')->assertOk()->assertExactJson($servers->jwks())
            ->assertHeader('Cache-Control', 'max-age=30, must-revalidate, public');
        $revision = 'second';
        $second = $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get('https://auth.example/oauth/jwks')
            ->assertOk()->assertExactJson($servers->jwks())->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public');
        $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }
}

class MetadataRouteDefaults
{
    /**
     * Supply the resolved server's normal route parameter.
     */
    public function handle(Request $request, Closure $next): Response
    {
        URL::defaults(['server' => 'example']);

        return $next($request);
    }
}
