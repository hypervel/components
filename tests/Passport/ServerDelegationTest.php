<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use DateInterval;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Passport\AuthCode;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Bridge\User;
use Hypervel\Passport\Client;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\DeviceCode;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\ServerSettings;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Passport\Token;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

class ServerDelegationTest extends TestCase
{
    /** @var list<array{private: string, public: string}> */
    protected static array $keys = [];

    protected DelegationSettingsResolver $settings;

    protected Client $client;

    /**
     * Configure OAuth services for direct server consumers.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('passport', require __DIR__ . '/../../src/passport/config/passport.php');
    }

    /**
     * Build two independently keyed servers and an app consumer that holds injected servers.
     */
    protected function setUpInCoroutine(): void
    {
        foreach ([
            '2016_06_01_000001_create_oauth_auth_codes_table.php',
            '2016_06_01_000002_create_oauth_access_tokens_table.php',
            '2016_06_01_000004_create_oauth_clients_table.php',
            '2024_06_01_000001_create_oauth_device_codes_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../src/passport/database/migrations/' . $migration)->up();
        }

        if (self::$keys === []) {
            self::$keys = [(new KeyGenerator)->generate(2048), (new KeyGenerator)->generate(2048)];
        }

        $this->settings = new DelegationSettingsResolver([
            'first' => new ServerSettings(
                [new ServerKey(self::$keys[0]['public'], self::$keys[0]['private'])],
                scopes: ['first' => 'First scope'],
                issuer: 'https://first.example',
            ),
            'second' => new ServerSettings(
                [new ServerKey(self::$keys[1]['public'], self::$keys[1]['private'])],
                clientCredentialsTokensExpireIn: new DateInterval('PT20M'),
                scopes: ['second' => 'Second scope'],
                issuer: 'https://second.example',
            ),
        ]);
        $this->app->instance(ServerSettingsResolver::class, $this->settings);
        $this->app->bind(AuthorizationServer::class, fn () => $this->app->make(ServerFactory::class)->authorizationServer());
        $this->app->bind(ResourceServer::class, fn () => $this->app->make(ServerFactory::class)->resourceServer());
        Passport::$deviceCodeGrantEnabled = true;
        Route::get('/oauth/device', static fn (): string => 'Device')->name('passport.device');
        Route::getRoutes()->refreshNameLookups();

        $this->client = Client::forceCreate([
            'name' => 'Client', 'secret' => 'client-secret', 'revoked' => false,
            'redirect_uris' => ['https://client.example/callback'],
            'grant_types' => ['authorization_code', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code'],
        ]);
    }

    public function testHeldServersFollowSettingsAndDirectValidationRechecksRevocation(): void
    {
        $consumer = $this->app->make(HeldPassportServers::class);
        $this->assertSame($consumer, $this->app->make(HeldPassportServers::class));
        $this->settings->identifier = 'second';
        $body = $this->issue($consumer->authorization, 'second');
        $jwt = (new Parser(new JoseEncoder))->parse($body['access_token']);
        $this->assertSame('https://second.example', $jwt->claims()->get('iss'));
        $this->assertGreaterThanOrEqual(1198, $body['expires_in']);
        $this->assertLessThanOrEqual(1200, $body['expires_in']);
        $request = new ServerRequest('GET', 'https://second.example/api', ['Authorization' => 'Bearer ' . $body['access_token']]);
        $result = $consumer->resource->validateAuthenticatedRequest($request);
        $this->assertSame($this->client->id, $result->getAttribute('oauth_client_id'));
        $httpRequest = Request::create('https://second.example/api');
        $httpRequest->headers->set('Authorization', 'Bearer ' . $body['access_token']);
        $currentCalls = $this->settings->currentCalls;
        $this->app->make(ServerFactory::class)->validateAuthenticatedRequest($httpRequest);
        $this->assertSame($currentCalls + 1, $this->settings->currentCalls);
        Token::findOrFail($result->getAttribute('oauth_access_token_id'))->revoke();

        $this->expectException(OAuthServerException::class);
        $consumer->resource->validateAuthenticatedRequest($request);
    }

    public function testHeldAuthorizationServerFollowsAKeyActivation(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $this->settings->settings['rotation'] = new ServerSettings([
            new ServerKey(self::$keys[0]['public'], self::$keys[0]['private'], $now->subDay()),
            new ServerKey(self::$keys[1]['public'], self::$keys[1]['private'], $now->addMinute()),
        ]);
        $this->settings->identifier = 'rotation';
        $consumer = $this->app->make(HeldPassportServers::class);
        $first = $this->issue($consumer->authorization);
        CarbonImmutable::setTestNow($now->addMinutes(2));
        $second = $this->issue($consumer->authorization);
        $parser = new Parser(new JoseEncoder);
        $this->assertNotSame($parser->parse($first['access_token'])->headers()->get('kid'), $parser->parse($second['access_token'])->headers()->get('kid'));

        foreach ([$first, $second] as $body) {
            $result = $consumer->resource->validateAuthenticatedRequest(new ServerRequest(
                'GET',
                'https://example.test/api',
                ['Authorization' => 'Bearer ' . $body['access_token']],
            ));
            $this->assertSame($this->client->id, $result->getAttribute('oauth_client_id'));
        }
    }

    public function testExtendedResponseKeepsItsSigningKeyAndSettingsAcrossActivation(): void
    {
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000007_create_oauth_signing_keys_table.php')->up();
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $original = SigningKey::forceCreate([
            'public_key' => self::$keys[0]['public'], 'private_key' => self::$keys[0]['private'],
            'activates_at' => $now->subDay(),
        ]);
        $replacement = SigningKey::forceCreate([
            'public_key' => self::$keys[1]['public'], 'private_key' => self::$keys[1]['private'],
            'activates_at' => $now->addMinute(),
        ]);
        $servers = $this->app->make(ServerFactory::class);
        $this->settings->settings['rotation'] = new ServerSettings(
            $this->app->make(SigningKeyRepository::class)->serverKeys(),
            clientCredentialsTokensExpireIn: new DateInterval('PT5M'),
            issuer: 'https://rotation.example',
        );
        $this->settings->identifier = 'rotation';
        Passport::useAuthorizationServerResponseType(new class($servers, $this->settings) extends BearerTokenResponse {
            /**
             * Keep the factory and the resolver shared with the issuing server.
             */
            public function __construct(protected ServerFactory $servers, protected DelegationSettingsResolver $settings)
            {
            }

            /**
             * Sign an additional token after activation and a request settings change.
             */
            protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
            {
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
                $this->settings->identifier = 'second';
                $lifetime = new DateInterval('P1M');
                $key = $this->servers->signingKey($lifetime);
                $token = (new Builder(new JoseEncoder, ChainedFormatter::default()))
                    ->withHeader('kid', $key->getKeyId())
                    ->issuedBy($this->servers->settings()->issuer)
                    ->expiresAt(CarbonImmutable::now()->add($lifetime))
                    ->withClaim('access_token', $accessToken->toString())
                    ->getToken(new Sha256, InMemory::plainText($key->getKeyContents()));

                return ['extension_token' => $token->toString()];
            }
        });
        $consumer = $this->app->make(HeldPassportServers::class);
        $body = $this->issue($consumer->authorization);
        $parser = new Parser(new JoseEncoder);
        $access = $parser->parse($body['access_token']);
        $extension = $parser->parse($body['extension_token']);
        $this->assertSame($access->headers()->get('kid'), $extension->headers()->get('kid'));
        $this->assertSame($body['access_token'], $extension->claims()->get('access_token'));

        foreach ([$access, $extension] as $token) {
            $this->assertSame('https://rotation.example', $token->claims()->get('iss'));
            $this->assertTrue((new Sha256)->verify($token->signature()->hash(), $token->payload(), InMemory::plainText(self::$keys[0]['public'])));
        }

        $this->assertGreaterThanOrEqual(31 * 86400, $original->fresh()->max_token_lifetime);
        $this->assertGreaterThanOrEqual($extension->claims()->get('exp')->getTimestamp(), $replacement->activates_at->getTimestamp() + $original->fresh()->max_token_lifetime);
        $this->assertSame(0, $replacement->fresh()->max_token_lifetime);

        $this->settings->identifier = 'rotation';
        $next = $this->issue($consumer->authorization);
        $this->assertNotSame($access->headers()->get('kid'), $parser->parse($next['access_token'])->headers()->get('kid'));
        $this->assertGreaterThanOrEqual(31 * 86400, $replacement->fresh()->max_token_lifetime);
    }

    public function testHeldConsentAndDeviceEntryPointsKeepCurrentScopesAndCapturedProvider(): void
    {
        $consumer = $this->app->make(HeldPassportServers::class);
        $this->settings->identifier = 'second';
        $authorization = $consumer->authorization->validateAuthorizationRequest(
            (new ServerRequest('GET', 'https://second.example/oauth/authorize'))->withQueryParams([
                'response_type' => 'code', 'client_id' => $this->client->id,
                'redirect_uri' => 'https://client.example/callback', 'scope' => 'second',
            ])
        );
        $authorization->setUser(new User('1'));
        $authorization->setAuthorizationApproved(true);
        TokenIssuance::run($this->app->make(ServerFactory::class), function () use ($consumer, $authorization): void {
            $response = $consumer->authorization->completeAuthorizationRequest($authorization, new Response);
            $this->assertSame(302, $response->getStatusCode());
        }, ['provider' => 'users']);
        $this->assertSame('users', AuthCode::sole()->provider);
        $this->assertSame(['second'], AuthCode::sole()->scopes);

        $response = $consumer->authorization->respondToDeviceAuthorizationRequest(
            (new ServerRequest('POST', 'https://second.example/oauth/device/code'))->withParsedBody([
                'client_id' => $this->client->id, 'scope' => 'second',
            ]),
            new Response,
        );
        $body = json_decode((string) $response->getBody(), true);
        TokenIssuance::run($this->app->make(ServerFactory::class), static function () use ($consumer, $body): void {
            $consumer->authorization->completeDeviceAuthorizationRequest($body['device_code'], '1', true);
        }, ['provider' => 'users']);
        $code = DeviceCode::sole();
        $this->assertSame('users', $code->provider);
        $this->assertSame(['second'], $code->scopes);
        $this->assertNotNull($code->user_approved_at);
    }

    public function testNestedPersonalAccessIssuanceSelectsItsOwnSettingsAndRestoresTheOuterOperation(): void
    {
        Client::forceCreate([
            'name' => 'Personal client', 'secret' => 'personal-secret', 'provider' => 'users',
            'revoked' => false, 'redirect_uris' => [], 'grant_types' => ['personal_access'],
        ]);
        $factory = $this->app->make(ServerFactory::class);

        TokenIssuance::run($factory, function () use ($factory): void {
            $outer = TokenIssuance::current();
            $this->settings->identifier = 'second';
            $held = $factory->personalAccessServer();
            $response = $held->respondToAccessTokenRequest(
                (new ServerRequest('POST', 'https://second.example/oauth/token'))->withParsedBody([
                    'grant_type' => 'personal_access', 'provider' => 'users', 'user_id' => '1',
                    'name' => 'Nested token', 'scope' => 'second',
                ]),
                new Response,
            );
            $body = json_decode((string) $response->getBody(), true);
            $jwt = (new Parser(new JoseEncoder))->parse($body['access_token']);
            $this->assertSame('https://second.example', $jwt->claims()->get('iss'));
            $token = Token::findOrFail($body['access_token_id']);
            $this->assertSame('users', $token->provider);
            $this->assertSame('Nested token', $token->name);
            $this->assertSame($outer, TokenIssuance::current());
            $this->assertSame('https://first.example', $factory->settings()->issuer);
            $this->assertSame(['provider' => 'admins'], $outer->attributes());
        }, ['provider' => 'admins']);
    }

    /**
     * Issue a client-credentials token through the caller's retained server.
     */
    protected function issue(AuthorizationServer $server, string $scope = ''): array
    {
        $response = $server->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://example.test/oauth/token'))->withParsedBody([
                'grant_type' => 'client_credentials', 'client_id' => $this->client->id,
                'client_secret' => 'client-secret', 'scope' => $scope,
            ]),
            new Response,
        );

        return json_decode((string) $response->getBody(), true);
    }
}

class DelegationSettingsResolver implements ServerSettingsResolver
{
    public string $identifier = 'first';

    public int $currentCalls = 0;

    /**
     * Keep settings that the test can switch between requests.
     *
     * @param array<string, ServerSettings> $settings
     */
    public function __construct(public array $settings)
    {
    }

    /**
     * Select the current request's settings.
     */
    public function current(): ?string
    {
        ++$this->currentCalls;

        return $this->identifier;
    }

    /**
     * Load the selected settings.
     */
    public function resolve(string $identifier): ServerSettings
    {
        return $this->settings[$identifier];
    }
}

class HeldPassportServers
{
    /**
     * Retain injected servers like an application controller or middleware.
     */
    public function __construct(public AuthorizationServer $authorization, public ResourceServer $resource)
    {
    }
}
