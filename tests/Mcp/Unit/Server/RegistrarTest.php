<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Http\Request;
use Hypervel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Passport;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Routing\Route as RoutingRoute;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Mcp\Fixtures\CustomPassportClient;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\Fixtures\PassportClient;
use Hypervel\Tests\Mcp\Fixtures\PassportClientRepository;
use Hypervel\Tests\Mcp\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Swoole\Coroutine\CanceledException;

class RegistrarTest extends TestCase
{
    /**
     * Load Passport's registration limiter and client services.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [...parent::getPackageProviders($app), PassportServiceProvider::class];
    }

    /**
     * Configure isolated client storage.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('database.default', 'clients');
        $app->make('config')->set('database.connections.clients', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app->make('config')->set('passport.connection', 'clients');
    }

    /**
     * Use the test client model and repository.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Passport::useClientModel(PassportClient::class);
        $this->app->instance(ClientRepository::class, new PassportClientRepository);
    }

    /**
     * Create the client columns used by the current registration case.
     */
    private function createOauthClientsTable(bool $withScopesColumn, ?string $scopesDefault = null, array $metadataColumns = []): void
    {
        $model = Passport::client();
        $schema = $model->getConnection()->getSchemaBuilder();

        $schema->dropIfExists($model->getTable());
        $schema->create($model->getTable(), function (Blueprint $table) use ($withScopesColumn, $scopesDefault, $metadataColumns): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->jsonb('grant_types');
            $table->jsonb('redirect_uris');

            foreach ($metadataColumns as $column) {
                $table->text($column)->nullable();
            }

            if ($withScopesColumn && $scopesDefault !== null) {
                $table->jsonb('scopes')->default($scopesDefault);
            } elseif ($withScopesColumn) {
                $table->jsonb('scopes')->nullable();
            }
        });
    }

    /**
     * Create a repository that stores the test client model.
     */
    private function databaseClientRepository(?array $initialScopes = null): object
    {
        return new class($initialScopes) {
            public Model $client;

            /**
             * Set the scopes supplied at client creation.
             */
            public function __construct(protected ?array $initialScopes = null)
            {
            }

            /**
             * Store the client with the supplied scopes.
             */
            public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): Model
            {
                $client = Passport::client()->forceFill([
                    'id' => 'test-client-id',
                    'name' => $name,
                    'grant_types' => ['authorization_code'],
                    'redirect_uris' => $redirectUris,
                    ...($this->initialScopes === null ? [] : ['scopes' => $this->initialScopes]),
                ]);
                $client->save();

                return $this->client = $client;
            }
        };
    }

    /**
     * Prepare registration with the requested metadata columns.
     */
    private function prepareOauthRegistration(array $metadataColumns = ['logo_uri', 'client_uri'], string $clientModel = PassportClient::class): object
    {
        Passport::useClientModel($clientModel);
        $this->createOauthClientsTable(withScopesColumn: false, metadataColumns: $metadataColumns);

        $repository = $this->databaseClientRepository();
        app()->instance(ClientRepository::class, $repository);
        (new Registrar)->oauthRoutes();

        return $repository;
    }

    public function testRegistersALocalServerAndRetrievesIt(): void
    {
        $registrar = new Registrar;

        $registrar->local('test-server', ExampleServer::class);

        $server = $registrar->getLocalServer('test-server');

        $this->assertIsCallable($server);
    }

    public function testReturnsNullForNonExistentLocalServer(): void
    {
        $registrar = new Registrar;

        $server = $registrar->getLocalServer('non-existent');

        $this->assertNull($server);
    }

    public function testRegistersAWebServerAndRetrievesIt(): void
    {
        $registrar = new Registrar;

        $route = $registrar->web('/api/mcp', ExampleServer::class);

        $webServer = $registrar->getWebServer('api/mcp');

        $this->assertInstanceOf(RoutingRoute::class, $webServer);
        $this->assertSame($route, $webServer);
    }

    public function testReturnsNullForNonExistentWebServer(): void
    {
        $registrar = new Registrar;

        $server = $registrar->getWebServer('non-existent');

        $this->assertNull($server);
    }

    public function testReturnsAllRegisteredServers(): void
    {
        $registrar = new Registrar;

        $registrar->local('local-server', ExampleServer::class);
        $registrar->web('/web/mcp', ExampleServer::class);

        $servers = $registrar->servers();

        $this->assertCount(2, $servers);
        $this->assertArrayHasKey('local-server', $servers);
        $this->assertArrayHasKey('web/mcp', $servers);
    }

    public function testRegistersOauthRoutes(): void
    {
        $registrar = new Registrar;

        $registrar->oauthRoutes();

        // Get the registered routes
        $routes = Route::getRoutes();
        $hasProtectedResource = false;
        $hasAuthServer = false;

        foreach ($routes as $route) {
            if ($route->getName() === 'mcp.oauth.protected-resource') {
                $hasProtectedResource = true;
            }

            if ($route->getName() === 'mcp.oauth.authorization-server') {
                $hasAuthServer = true;
            }
        }

        $this->assertTrue($hasProtectedResource);
        $this->assertTrue($hasAuthServer);
    }

    public function testRegistersOauthRoutesWithCustomPrefix(): void
    {
        $registrar = new Registrar;

        $registrar->oauthRoutes('custom-oauth');

        // Get the registered routes
        $routes = Route::getRoutes();
        $hasProtectedResource = false;
        $hasAuthServer = false;
        $hasRegisterRoute = false;

        foreach ($routes as $route) {
            if ($route->getName() === 'mcp.oauth.protected-resource') {
                $hasProtectedResource = true;
            }

            if ($route->getName() === 'mcp.oauth.authorization-server') {
                $hasAuthServer = true;
            }

            if ($route->uri() === 'custom-oauth/register') {
                $hasRegisterRoute = true;
            }
        }

        $this->assertTrue($hasProtectedResource);
        $this->assertTrue($hasAuthServer);
        $this->assertTrue($hasRegisterRoute);
    }

    public function testDoesNotOverrideAnExistingExactOauthAuthorizationServerRoute(): void
    {
        Route::get('/.well-known/oauth-authorization-server', fn () => response()->json(['existing' => true]));
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $response->assertJson(['existing' => true]);

        $nestedResponse = $this->getJson('/.well-known/oauth-authorization-server/mcp/weather');

        $nestedResponse->assertStatus(200);
        $nestedResponse->assertJson([
            'issuer' => url('/'),
            'registration_endpoint' => url('oauth/register'),
            'scopes_supported' => ['mcp:use'],
        ]);
    }

    public function testDoesNotOverrideAnExistingExactOauthProtectedResourceRoute(): void
    {
        Route::get('/.well-known/oauth-protected-resource', fn () => response()->json(['existing' => true]));
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertStatus(200);
        $response->assertJson(['existing' => true]);

        $nestedResponse = $this->getJson('/.well-known/oauth-protected-resource/mcp/weather');

        $nestedResponse->assertStatus(200);
        $nestedResponse->assertJson([
            'resource' => url('/mcp/weather'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp:use'],
        ]);
    }

    public function testResolvesTheNestedProtectedResourcePathWhenARouteParameterPrecedesIt(): void
    {
        Route::group(['domain' => '{account}.example.com'], function (): void {
            (new Registrar)->oauthRoutes();
        });

        $response = $this->getJson('http://tenant.example.com/.well-known/oauth-protected-resource/mcp/server');

        $response->assertStatus(200);
        $response->assertJson([
            'resource' => 'http://tenant.example.com/mcp/server',
        ]);
    }

    public function testAddsMcpScopeWhenPassportIsAvailable(): void
    {
        $registrar = new Registrar;

        // Clear any existing scopes
        Passport::$scopes = [];

        $registrar->oauthRoutes();

        $this->assertArrayHasKey('mcp:use', Passport::$scopes);
        $this->assertSame('Use MCP server', Passport::$scopes['mcp:use']);
    }

    public function testDoesNotDuplicateMcpScopeIfAlreadyExists(): void
    {
        $registrar = new Registrar;

        // Set existing scope
        Passport::$scopes = ['mcp:use' => 'Existing MCP scope'];

        $registrar->oauthRoutes();

        // Should not overwrite existing scope
        $this->assertSame('Existing MCP scope', Passport::$scopes['mcp:use']);
    }

    public function testHandlesOauthRegistrationEndpoint(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'client_id' => 'test-client-id',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'redirect_uris' => ['http://localhost:3000/callback'],
            'scope' => 'mcp:use',
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    public function testRegistrationHonorsTheApplicationPassportRateLimit(): void
    {
        $this->app->make(RateLimiter::class)->for('passport', fn (): Limit => Limit::perMinute(1));
        (new Registrar)->oauthRoutes();

        $registration = ['redirect_uris' => ['https://example.com/callback']];

        $this->postJson('/oauth/register', $registration)->assertCreated();
        $this->postJson('/oauth/register', $registration)->assertTooManyRequests();
    }

    public function testRegistrationCancellationIsNotConvertedToAServerError(): void
    {
        $exception = new CanceledException('Registration canceled.');
        $repository = m::mock(ClientRepository::class);
        $repository->shouldReceive('createAuthorizationCodeGrantClient')->once()->andThrow($exception);
        $this->app->instance(ClientRepository::class, $repository);

        try {
            (new OAuthRegisterController)(Request::create('/oauth/register', 'POST', [
                'redirect_uris' => ['https://example.com/callback'],
            ]));
            $this->fail('Registration must propagate cancellation.');
        } catch (CanceledException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    public function testIgnoresRegistrationMetadataWhenTheClientIsNotAnEloquentModel(): void
    {
        (new Registrar)->oauthRoutes();

        $this->postJson('/oauth/register', [
            'redirect_uris' => ['http://localhost:3000/callback'],
            'logo_uri' => 'https://example.com/logo.png',
            'client_uri' => 'https://example.com',
        ])->assertCreated()->assertJsonMissingPath('logo_uri')->assertJsonMissingPath('client_uri');
    }

    #[DataProvider('registrationMetadata')]
    public function testPersistsAndReturnsSupportedRegistrationMetadata(array $expected): void
    {
        config()->set('passport.connection', 'clients');
        $repository = $this->prepareOauthRegistration(metadataColumns: array_keys($expected));

        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
            'logo_uri' => 'https://example.com/logo.png',
            'client_uri' => 'http://example.com',
        ])->assertCreated();

        $this->assertSame($expected, $response->collect()->only(['logo_uri', 'client_uri'])->all());
        $this->assertSame($expected, $repository->client->fresh()->only(array_keys($expected)));
    }

    /**
     * Return supported registration metadata combinations.
     */
    public static function registrationMetadata(): array
    {
        return [
            'both columns' => [['logo_uri' => 'https://example.com/logo.png', 'client_uri' => 'http://example.com']],
            'logo only' => [['logo_uri' => 'https://example.com/logo.png']],
            'website only' => [['client_uri' => 'http://example.com']],
            'neither column' => [[]],
        ];
    }

    #[DataProvider('missingMetadata')]
    public function testOmitsMissingOrNullRegistrationMetadataFromTheResponse(array $metadata): void
    {
        $repository = $this->prepareOauthRegistration();

        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
            ...$metadata,
        ])->assertCreated()->assertJsonMissingPath('logo_uri')->assertJsonMissingPath('client_uri');

        $this->assertSame(['logo_uri' => null, 'client_uri' => null], $repository->client->fresh()->only(['logo_uri', 'client_uri']));
    }

    /**
     * Return omitted and null metadata.
     */
    public static function missingMetadata(): array
    {
        return [
            'omitted' => [[]],
            'null' => [['logo_uri' => null, 'client_uri' => null]],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function testRejectsInvalidRegistrationMetadataBeforeCreatingAClient(string $attribute, mixed $value): void
    {
        $this->prepareOauthRegistration();

        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
            $attribute => $value,
        ])->assertBadRequest()->assertJson(['error' => 'invalid_client_metadata']);

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    /**
     * Return invalid values for each metadata field.
     */
    public static function invalidMetadata(): iterable
    {
        foreach (['logo_uri', 'client_uri'] as $attribute) {
            foreach ([
                'invalid URL' => 'not-a-url',
                'unsupported scheme' => 'ftp://example.com/logo.png',
                'non-string' => ['https://example.com'],
                'too long' => 'https://example.com/' . str_repeat('a', 2030),
            ] as $case => $value) {
                yield $attribute . ' ' . $case => [$attribute, $value];
            }
        }
    }

    public function testRejectsNonStringRedirectUrisBeforeCreatingAClient(): void
    {
        $this->prepareOauthRegistration();

        $this->postJson('/oauth/register', [
            'redirect_uris' => [42],
        ])->assertBadRequest()->assertJson(['error' => 'invalid_redirect_uri']);

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function testKeepsRedirectErrorsAheadOfMetadataErrors(): void
    {
        $this->prepareOauthRegistration();

        $this->postJson('/oauth/register', [
            'redirect_uris' => ['not-a-url'],
            'logo_uri' => 'not-a-url',
        ])->assertBadRequest()->assertExactJson([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'redirect_uris.0 is not a valid URL.',
        ]);
    }

    public function testDescribesTheRedirectErrorWhenAnEarlierRuleAlsoFails(): void
    {
        $this->prepareOauthRegistration();

        $this->postJson('/oauth/register', [
            'client_name' => 123,
            'redirect_uris' => ['not-a-url'],
        ])->assertBadRequest()->assertExactJson([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'redirect_uris.0 is not a valid URL.',
        ]);
    }

    #[DataProvider('authorizeMetadata')]
    public function testRendersClientMetadataOnTheAuthorizeView(array $metadataColumns, array $attributes, array $expected, array $missing): void
    {
        $this->withoutVite();
        $this->prepareOauthRegistration(metadataColumns: $metadataColumns);
        Route::post('oauth/authorize', fn (): null => null)->name('passport.authorizations.approve');
        Route::delete('oauth/authorize', fn (): null => null)->name('passport.authorizations.deny');
        Model::preventAccessingMissingAttributes();

        $client = Passport::client()->forceFill(['id' => 'client-id', 'name' => 'Example', 'grant_types' => [], 'redirect_uris' => [], ...$attributes]);
        $client->save();

        $html = view('mcp::authorize', [
            'client' => $client->fresh(),
            'user' => (object) ['email' => 'user@example.com'],
            'scopes' => [],
            'authToken' => 'token',
            'appearance' => 'light',
        ])->render();

        Model::preventAccessingMissingAttributes(false);

        foreach ($expected as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }

        foreach ($missing as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
    }

    /**
     * Return authorization view metadata cases.
     */
    public static function authorizeMetadata(): array
    {
        return [
            'with metadata' => [
                ['logo_uri', 'client_uri'],
                ['logo_uri' => 'https://example.com/logo.png', 'client_uri' => 'https://example.com'],
                ['<img src="https://example.com/logo.png"', '<a href="https://example.com"'],
                ['h-12 w-12 text-primary'],
            ],
            'without columns' => [
                [],
                [],
                ['h-12 w-12 text-primary'],
                ['<img', '<a href='],
            ],
        ];
    }

    public function testReturnsMetadataTransformedByACustomClientModel(): void
    {
        $repository = $this->prepareOauthRegistration(clientModel: CustomPassportClient::class);

        $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
            'logo_uri' => 'https://example.com/LOGO.png',
        ])->assertCreated()->assertJsonPath('logo_uri', 'https://example.com/logo.png');

        $this->assertSame('https://example.com/logo.png', $repository->client->fresh()->logo_uri);
    }

    public function testPersistsTheAdvertisedMcpScopeWhenPassportClientsAreScopeRestrictedByDefault(): void
    {
        $this->createOauthClientsTable(withScopesColumn: true, scopesDefault: '[]');
        $clientRepository = $this->databaseClientRepository();
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['scope' => 'mcp:use']);

        $this->assertSame(['mcp:use'], $clientRepository->client->fresh()?->scopes);
    }

    public function testPreservesScopesGrantedAtCreationWhenPersistingTheMcpScope(): void
    {
        $this->createOauthClientsTable(withScopesColumn: true);
        $clientRepository = $this->databaseClientRepository(initialScopes: ['custom:scope']);
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);

        $this->assertSame(['custom:scope', 'mcp:use'], $clientRepository->client->fresh()?->scopes);
    }

    public function testDoesNotDuplicateTheMcpScopeWhenItWasAlreadyGrantedAtCreation(): void
    {
        $this->createOauthClientsTable(withScopesColumn: true);
        $clientRepository = $this->databaseClientRepository(initialScopes: ['mcp:use']);
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);

        $this->assertSame(['mcp:use'], $clientRepository->client->fresh()?->scopes);
    }

    public function testLeavesPassportClientsUnrestrictedWhenTheScopesValueIsNull(): void
    {
        $this->createOauthClientsTable(withScopesColumn: true);
        $clientRepository = $this->databaseClientRepository();
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['scope' => 'mcp:use']);

        $this->assertNull($clientRepository->client->fresh()?->scopes);
    }

    public function testLeavesPassportClientsUnrestrictedWhenClientScopesAreNotEnabled(): void
    {
        $this->createOauthClientsTable(withScopesColumn: false);
        $clientRepository = $this->databaseClientRepository();
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['scope' => 'mcp:use']);

        $this->assertArrayNotHasKey('scopes', $clientRepository->client->fresh()?->getAttributes());
    }

    public function testFallsBackToTheRedirectHostWhenNoClientNameIsProvided(): void
    {
        $clientRepository = new class {
            public ?string $capturedName = null;

            /**
             * Capture the resolved client name.
             */
            public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): stdClass
            {
                $this->capturedName = $name;

                return (object) [
                    'id' => 'test-client-id',
                    'grant_types' => ['authorization_code'],
                    'redirect_uris' => $redirectUris,
                ];
            }
        };

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertStatus(201);

        $this->assertSame('example.com', $clientRepository->capturedName);
    }

    public function testReturnsInvalidClientMetadataWhenClientMetadataIsInvalid(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $response = $this->postJson('/oauth/register', [
            'client_name' => str_repeat('a', 256),
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client_metadata',
        ]);
    }

    public function testPreservesAFalsyClientNameForOauthRegistration(): void
    {
        $clientRepository = new class {
            public ?string $capturedName = null;

            /**
             * Capture the resolved client name.
             */
            public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): stdClass
            {
                $this->capturedName = $name;

                return (object) [
                    'id' => 'test-client-id',
                    'grant_types' => ['authorization_code'],
                    'redirect_uris' => $redirectUris,
                ];
            }
        };

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => '0',
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertStatus(201);

        $this->assertSame('0', $clientRepository->capturedName);
    }

    public function testFallsBackToTheLegacyNameFieldForOauthRegistration(): void
    {
        $clientRepository = new class {
            public ?string $capturedName = null;

            /**
             * Capture the resolved client name.
             */
            public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): stdClass
            {
                $this->capturedName = $name;

                return (object) [
                    'id' => 'test-client-id',
                    'grant_types' => ['authorization_code'],
                    'redirect_uris' => $redirectUris,
                ];
            }
        };

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'name' => 'Legacy Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);

        $this->assertSame('Legacy Client', $clientRepository->capturedName);
    }

    public function testPrefersClientNameOverNameForOauthRegistration(): void
    {
        $clientRepository = new class {
            public ?string $capturedName = null;

            /**
             * Capture the resolved client name.
             */
            public function createAuthorizationCodeGrantClient(string $name, array $redirectUris, bool $confidential = true, ?Authenticatable $user = null, bool $enableDeviceFlow = false): stdClass
            {
                $this->capturedName = $name;

                return (object) [
                    'id' => 'test-client-id',
                    'grant_types' => ['authorization_code'],
                    'redirect_uris' => $redirectUris,
                ];
            }
        };

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $this->app->instance(ClientRepository::class, $clientRepository);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Preferred Client',
            'name' => 'Legacy Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);

        $this->assertSame('Preferred Client', $clientRepository->capturedName);
    }

    public function testHandlesOauthRegistrationWithAllowedDomains(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', ['http://localhost:3000/']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'client_id' => 'test-client-id',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'redirect_uris' => ['http://localhost:3000/callback'],
            'scope' => 'mcp:use',
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    #[DataProvider('loopbackRedirects')]
    public function testAllowsLocalhostWithDynamicPortWhenLocalhostIsInRedirectDomains(string $uri): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', ['https://example.com', 'http://localhost']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => [$uri],
        ]);

        $response->assertStatus(201);
    }

    /**
     * Return loopback redirects using dynamic ports.
     */
    public static function loopbackRedirects(): array
    {
        return [
            'localhost' => ['http://localhost:18293/callback'],
            '127.0.0.1' => ['http://127.0.0.1:29100/callback'],
            'IPv6 loopback' => ['http://[::1]:39201/callback'],
        ];
    }

    #[DataProvider('loopbackRedirects')]
    public function testRejectsLocalhostWithDynamicPortWhenLocalhostIsNotInRedirectDomains(string $uri): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', ['https://example.com']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => [$uri],
        ]);

        $response->assertStatus(400);
    }

    public function testDoesNotAllowNonLocalhostUrlsWhenLocalhostIsInRedirectDomains(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', ['https://example.com', 'http://localhost']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://evil.com:18293/callback'],
        ]);

        $response->assertStatus(400);
    }

    #[DataProvider('redirectsWithUserInfo')]
    public function testRejectsRedirectUrisWithUserinfo(string $uri, array $domains): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', $domains);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => [$uri],
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);
    }

    /**
     * Return redirects containing user information.
     */
    public static function redirectsWithUserInfo(): array
    {
        return [
            ['http://localhost:53123@evil.example/cb', ['https://example.com', 'http://localhost']],
            ['http://127.0.0.1:1@evil.example/cb', ['https://example.com', 'http://127.0.0.1']],
            ['http://localhost@evil.example/cb', ['https://example.com', 'http://localhost']],
            ['https://example.com@evil.example/cb', ['https://example.com']],
            ['https://user:pass@example.com/cb', ['*']],
        ];
    }

    public function testDoesNotAllowHttpsLocalhostUrlsViaLocalhostRedirectDomain(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', ['https://example.com', 'http://localhost']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['https://localhost:18293/callback'],
        ]);

        $response->assertStatus(400);
    }

    #[DataProvider('loopbackDomains')]
    public function testAllowsAllLocalhostHostsWhenAnyLocalhostVariantIsInRedirectDomains(string $configDomain): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', [$configDomain]);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://localhost:18293/callback'],
        ]);

        $response->assertStatus(201);
    }

    /**
     * Return configured loopback domain forms.
     */
    public static function loopbackDomains(): array
    {
        return [
            'http://127.0.0.1' => ['http://127.0.0.1'],
            'http://[::1]' => ['http://[::1]'],
            'localhost without scheme' => ['localhost'],
        ];
    }

    public function testHandlesOauthRegistrationWithIncorrectRedirectDomain(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.redirect_domains', ['http://allowed-domain.com/']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://not-allowed.com/callback'],
        ]);

        $response->assertStatus(400);
    }

    public function testHandlesOauthDiscoveryWithMultiSegmentPaths(): void
    {
        // Fake Passport's routes so the oauthRoutes() helper can resolve them.
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        // Test protected resource endpoint with multi-segment path
        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp/weather');

        $response->assertStatus(200);
        $response->assertJson([
            'resource' => url('/mcp/weather'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp:use'],
        ]);

        // Test authorization server endpoint with multi-segment path
        $response = $this->getJson('/.well-known/oauth-authorization-server/mcp/weather');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'registration_endpoint',
            'response_types_supported',
            'code_challenge_methods_supported',
            'scopes_supported',
            'grant_types_supported',
        ]);
        $response->assertJson([
            'issuer' => url('/'),
            'scopes_supported' => ['mcp:use'],
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        ]);
    }

    public function testHandlesOauthDiscoveryWithSingleSegmentPaths(): void
    {
        // Fake Passport's routes so the oauthRoutes() helper can resolve them.
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        // Test backward compatibility with single-segment paths
        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp');

        $response->assertStatus(200);
        $response->assertJson([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp:use'],
        ]);

        $response = $this->getJson('/.well-known/oauth-authorization-server/mcp');

        $response->assertStatus(200);
        $response->assertJson([
            'issuer' => url('/'),
        ]);
    }

    public function testHandlesOauthDiscoveryWithNoPath(): void
    {
        // Fake Passport's routes so the oauthRoutes() helper can resolve them.
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        // Test with no path (root)
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertStatus(200);
        $response->assertJson([
            'resource' => url('/'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['mcp:use'],
        ]);

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $response->assertJson([
            'issuer' => url('/'),
        ]);
    }

    public function testUsesConfiguredAuthorizationServerForAuthorizationServersField(): void
    {
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.authorization_server', 'https://auth.example.com');

        $response = $this->getJson('/.well-known/oauth-protected-resource/mcp');

        $response->assertStatus(200);
        $response->assertJson([
            'resource' => url('/mcp'),
            'authorization_servers' => ['https://auth.example.com'],
        ]);
    }

    public function testUsesConfiguredAuthorizationServerAsIssuerInAuthorizationServerMetadata(): void
    {
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.authorization_server', 'https://auth.example.com');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $response->assertJson([
            'issuer' => 'https://auth.example.com',
        ]);
    }

    #[DataProvider('customSchemeRedirects')]
    public function testAcceptsCustomSchemeRedirectUrisWhenTheSchemeIsConfigured(string $uri): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.custom_schemes', ['cursor', 'vscode', 'claude']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Desktop Client',
            'redirect_uris' => [$uri],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'client_id' => 'test-client-id',
            'redirect_uris' => [$uri],
        ]);
    }

    /**
     * Return supported custom scheme callbacks.
     */
    public static function customSchemeRedirects(): array
    {
        return [
            'cursor scheme' => ['cursor://anysphere.cursor-mcp/oauth/callback'],
            'vscode scheme' => ['vscode://extension.mcp/callback'],
            'claude scheme' => ['claude://desktop.app/oauth/callback'],
        ];
    }

    public function testRejectsCustomSchemeRedirectUrisWhenTheSchemeIsNotConfigured(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.custom_schemes', []);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Desktop Client',
            'redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback'],
        ]);

        $response->assertStatus(400);
    }

    public function testRejectsCustomSchemeRedirectUrisWhenADifferentSchemeIsConfigured(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.custom_schemes', ['vscode']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Desktop Client',
            'redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback'],
        ]);

        $response->assertStatus(400);
    }

    public function testRejectsCustomSchemeRedirectUrisWithMissingHost(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.custom_schemes', ['cursor']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Desktop Client',
            'redirect_uris' => ['cursor:///callback'],
        ]);

        $response->assertStatus(400);
    }

    public function testStillAllowsStandardHttpUrlsWhenCustomSchemesAreConfigured(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        config()->set('mcp.custom_schemes', ['cursor']);

        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Web Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertStatus(201);
    }

    public function testReturnsJsonValidationErrorsEvenWithoutAcceptApplicationJsonHeader(): void
    {
        $registrar = new Registrar;
        $registrar->oauthRoutes();

        $response = $this->post('/oauth/register', [
            'redirect_uris' => ['not-a-valid-url'],
        ], ['Accept' => '*/*']);

        $response->assertStatus(400);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['error', 'error_description']);
    }

    public function testDoesNotRegisterTheClientRegistrationRouteWhenRegistrationIsDisabled(): void
    {
        (new Registrar)->oauthRoutes(registration: false);

        $this->postJson('/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['https://example.com/callback'],
        ])->assertNotFound();
    }

    #[DataProvider('authorizationMetadataUris')]
    public function testOmitsTheRegistrationEndpointFromTheMetadataWhenRegistrationIsDisabled(string $uri): void
    {
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        (new Registrar)->oauthRoutes(registration: false);

        $response = $this->getJson($uri);

        $response->assertStatus(200);
        $response->assertJsonMissingPath('registration_endpoint');
        $response->assertJson([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'scopes_supported' => ['mcp:use'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        ]);
    }

    /**
     * Return authorization metadata discovery paths.
     */
    public static function authorizationMetadataUris(): array
    {
        return [
            ['/.well-known/oauth-authorization-server'],
            ['/.well-known/oauth-authorization-server/mcp/weather'],
        ];
    }

    public function testAdvertisesTheRegistrationEndpointUnderACustomPrefix(): void
    {
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        (new Registrar)->oauthRoutes('custom-oauth');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('registration_endpoint', url('custom-oauth/register'));
    }

    public function testAdvertisesACustomRegistrationRouteWhenRegistrationIsDisabled(): void
    {
        Route::get('/oauth/authorize')->name('passport.authorizations.authorize');
        Route::post('/oauth/token')->name('passport.token');

        (new Registrar)->oauthRoutes(registration: false);

        Route::post('/clients/register', OAuthRegisterController::class)
            ->middleware('throttle:60,1')
            ->name('mcp.oauth.register');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertStatus(200)
            ->assertJsonPath('registration_endpoint', url('clients/register'));
    }

    #[DataProvider('allowedRootRedirects')]
    public function testAcceptsAllowedDomainRootsWithoutChangingRedirectBytes(string $uri): void
    {
        config()->set('mcp.redirect_domains', ['https://example.com']);
        (new Registrar)->oauthRoutes();

        $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])
            ->assertCreated()->assertJsonPath('redirect_uris.0', $uri);
    }

    /**
     * Return root redirects with and without a query.
     */
    public static function allowedRootRedirects(): array
    {
        return [['https://example.com'], ['https://example.com?client=mcp']];
    }

    public function testRejectsALookalikeRedirectHost(): void
    {
        config()->set('mcp.redirect_domains', ['https://example.com']);
        (new Registrar)->oauthRoutes();

        $this->postJson('/oauth/register', ['redirect_uris' => ['https://example.com.evil.test?client=mcp']])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_redirect_uri');
    }

    #[DataProvider('redirectsWithFragments')]
    public function testRejectsRedirectFragmentsBeforeDomainAndSchemeAllowances(string $uri, array $domains): void
    {
        config()->set('mcp.redirect_domains', $domains);
        config()->set('mcp.custom_schemes', ['cursor']);
        (new Registrar)->oauthRoutes();

        $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_redirect_uri');
    }

    /**
     * Return forbidden fragments across supported redirect forms.
     */
    public static function redirectsWithFragments(): array
    {
        return [
            'HTTPS wildcard' => ['https://example.com/callback#fragment', ['*']],
            'loopback' => ['http://localhost:18293/callback#fragment', ['http://localhost']],
            'custom scheme' => ['cursor://extension/callback#fragment', []],
            'empty fragment' => ['https://example.com/callback#', ['https://example.com']],
        ];
    }
}
