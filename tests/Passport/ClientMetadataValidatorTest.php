<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientMetadataValidator;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Events\ClientMetadataValidated;
use Hypervel\Passport\Exceptions\InvalidClientMetadataException;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerSettings;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

class ClientMetadataValidatorTest extends TestCase
{
    /**
     * Load registration settings without requiring signing-key files.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('passport', require __DIR__ . '/../../src/passport/config/passport.php');
    }

    public function testEffectiveDefaultsAreReturnedAndUnrecognizedFieldsAreIgnored(): void
    {
        $metadata = $this->app->make(ClientMetadataValidator::class)->validate([
            'redirect_uris' => ['https://client.example/callback'],
            'owner_id' => 1, 'secret' => 'injected', 'unknown' => 'ignored',
        ]);

        $this->assertSame([
            'redirect_uris' => ['https://client.example/callback'],
            'client_name' => 'OAuth Client',
            'grant_types' => ['authorization_code'],
            'scope' => '',
            'application_type' => 'web',
            'token_endpoint_auth_method' => 'client_secret_basic',
            'dpop_bound_access_tokens' => false,
            'require_pushed_authorization_requests' => false,
            'response_types' => ['code'],
            'jwks' => null,
            'jwks_uri' => null,
            'token_endpoint_auth_signing_alg' => null,
        ], $metadata);
    }

    #[TestWith(['web', 'https://client.example/callback?context=original'])]
    #[TestWith(['native', 'https://client.example/callback'])]
    #[TestWith(['native', 'http://127.0.0.1:49152/callback'])]
    #[TestWith(['native', 'http://[::1]:49152/callback'])]
    #[TestWith(['native', 'com.example.app:/callback'])]
    public function testSupportedRedirectUrisRemainExact(string $applicationType, string $uri): void
    {
        $metadata = $this->app->make(ClientMetadataValidator::class)->validate([
            'application_type' => $applicationType, 'redirect_uris' => [$uri],
        ]);

        $this->assertSame([$uri], $metadata['redirect_uris']);
    }

    #[TestWith(['web', 'http://client.example/callback'])]
    #[TestWith(['web', 'http://127.0.0.1/callback'])]
    #[TestWith(['web', 'com.example.app:/callback'])]
    #[TestWith(['native', 'http://client.example/callback'])]
    #[TestWith(['native', 'myapp:/callback'])]
    #[TestWith(['native', '1com.example:/callback'])]
    #[TestWith(['native', 'com.example.app://callback'])]
    #[TestWith(['web', 'https://client.example/callback#fragment'])]
    #[TestWith(['web', 'https://client.example/*'])]
    #[TestWith(['web', 'https://user@client.example/callback'])]
    #[TestWith(['web', '/callback'])]
    public function testInvalidRedirectsReturnTheProtocolError(string $applicationType, string $uri): void
    {
        try {
            $this->app->make(ClientMetadataValidator::class)->validate([
                'application_type' => $applicationType, 'redirect_uris' => [$uri],
            ]);
            $this->fail('The invalid callback must be rejected.');
        } catch (InvalidClientMetadataException $exception) {
            $this->assertSame('invalid_redirect_uri', $exception->error);
        }
    }

    #[TestWith(['grant_types', ['password']])]
    #[TestWith(['grant_types', ['personal_access']])]
    #[TestWith(['response_types', ['token']])]
    #[TestWith(['response_types', []])]
    #[TestWith(['token_endpoint_auth_method', 'client_secret_jwt'])]
    #[TestWith(['dpop_bound_access_tokens', 'false'])]
    #[TestWith(['dpop_bound_access_tokens', true])]
    #[TestWith(['require_pushed_authorization_requests', 'false'])]
    #[TestWith(['scope', 'unknown'])]
    #[TestWith(['scope', "read\twrite"])]
    public function testInvalidCoreMetadataDoesNotReachExtensions(string $name, mixed $value): void
    {
        Passport::tokensCan(['read' => 'Read', 'write' => 'Write']);
        $calls = 0;
        Event::listen(ClientMetadataValidated::class, static function () use (&$calls): void {
            ++$calls;
        });

        try {
            $this->expectException(InvalidClientMetadataException::class);
            $this->app->make(ClientMetadataValidator::class)->validate([
                'redirect_uris' => ['https://client.example/callback'], $name => $value,
            ]);
        } finally {
            $this->assertSame(0, $calls);
        }
    }

    public function testMachineClientsNeedNoRedirectButMustAuthenticate(): void
    {
        $validator = $this->app->make(ClientMetadataValidator::class);
        $metadata = $validator->validate(['grant_types' => ['client_credentials']]);
        $this->assertSame([], $metadata['redirect_uris']);
        $this->assertSame([], $metadata['response_types']);

        $this->expectException(InvalidClientMetadataException::class);
        $validator->validate(['grant_types' => ['client_credentials'], 'token_endpoint_auth_method' => 'none']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testExtensionsReceiveSubmittedAndEffectiveMetadataOnCreateAndUpdate(bool $update): void
    {
        $client = $update ? new Client(['id' => 'existing']) : null;
        $token = new AccessToken(['oauth_scopes' => ['passport:register']]);
        $calls = 0;
        Event::listen(ClientMetadataValidated::class, function (ClientMetadataValidated $event) use ($client, $token, &$calls): void {
            ++$calls;
            $this->assertSame($client, $event->client);
            $this->assertSame($token, $event->token);
            $this->assertSame('custom', $event->submitted['extension_value']);
            $this->assertSame('client_secret_basic', $event->validated['token_endpoint_auth_method']);
            $event->extensions['extension_value'] = strtoupper($event->submitted['extension_value']);
        });

        $metadata = $this->app->make(ClientMetadataValidator::class)->validate([
            'redirect_uris' => ['https://client.example/callback'], 'extension_value' => 'custom',
        ], $client, $token);

        $this->assertSame('CUSTOM', $metadata['extension_value']);
        $this->assertSame(1, $calls);
    }

    #[TestWith(['scope'])]
    #[TestWith(['secret'])]
    #[TestWith(['owner_id'])]
    #[TestWith(['third_party'])]
    public function testExtensionsCannotOverrideCoreMetadataOrOwnership(string $name): void
    {
        Event::listen(ClientMetadataValidated::class, static function (ClientMetadataValidated $event) use ($name): void {
            $event->extensions[$name] = 'override';
        });

        $this->expectException(LogicException::class);
        $this->app->make(ClientMetadataValidator::class)->validate(['redirect_uris' => ['https://client.example/callback']]);
    }

    public function testPrivateKeyAuthenticationRequiresAnIssuerAndPublicKeys(): void
    {
        $servers = m::mock(ServerFactory::class);
        $servers->shouldReceive('settings')->andReturn(new ServerSettings([], issuer: 'https://issuer.example'), new ServerSettings([]));
        $this->app->instance(ServerFactory::class, $servers);
        $validator = $this->app->make(ClientMetadataValidator::class);
        $key = ParsedKey::fromPublicKey((new SigningKeyFactory)->definition()['public_key'])->getJwk();
        $request = [
            'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [$key]],
        ];
        $metadata = $validator->validate($request);
        $this->assertSame('RS256', $metadata['token_endpoint_auth_signing_alg']);

        $this->expectException(InvalidClientMetadataException::class);
        $validator->validate($request);
    }

    #[TestWith(['jwks'])]
    #[TestWith(['jwks_uri'])]
    public function testRegisteredKeysRequireAnAuthenticationMethodThatUsesThem(string $field): void
    {
        $key = ParsedKey::fromPublicKey((new SigningKeyFactory)->definition()['public_key'])->getJwk();
        $keys = ['keys' => [[...$key, 'use' => 'enc', 'alg' => 'RSA-OAEP-256']]];
        $validator = $this->app->make(ClientMetadataValidator::class);
        $this->expectException(InvalidClientMetadataException::class);
        $validator->validate([
            'redirect_uris' => ['https://client.example/callback'],
            $field => $field === 'jwks' ? $keys : 'https://keys.example/jwks',
        ]);
    }

    public function testInlineAndRemoteKeysCannotBeRegisteredTogether(): void
    {
        $this->expectException(InvalidClientMetadataException::class);
        $this->expectExceptionMessage('either jwks or jwks_uri');
        $this->app->make(ClientMetadataValidator::class)->validate([
            'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [['kty' => 'RSA']]],
            'jwks_uri' => 'https://keys.example/jwks',
        ]);
    }

    public function testInlineKeySetsUseTheRemoteDocumentSizeLimit(): void
    {
        $servers = m::mock(ServerFactory::class);
        $servers->shouldReceive('settings')->andReturn(new ServerSettings([], issuer: 'https://issuer.example'));
        $this->app->instance(ServerFactory::class, $servers);
        $key = ParsedKey::fromPublicKey((new SigningKeyFactory)->definition()['public_key'])->getJwk();

        $this->expectException(InvalidClientMetadataException::class);
        $this->expectExceptionMessage('maximum size');
        $this->app->make(ClientMetadataValidator::class)->validate([
            'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks' => ['keys' => [$key], 'extra' => str_repeat('a', 65536)],
        ]);
    }
}
