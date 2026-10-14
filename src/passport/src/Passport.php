<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\AccessToken as AccessTokenEntity;
use Hypervel\Passport\Contracts\AuthorizationViewResponse;
use Hypervel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Hypervel\Passport\Contracts\DeviceUserCodeViewResponse;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Http\Responses\SimpleViewResponse;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResourceServer;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Mockery;
use Psr\Http\Message\ServerRequestInterface;

class Passport
{
    public const string DEFAULT_GUARD = 'passport';

    public const string DEFAULT_COOKIE = 'hypervel_token';

    protected const bool DEFAULT_VALIDATE_KEY_PERMISSIONS = true;

    protected const bool DEFAULT_REVOKE_REFRESH_TOKEN_AFTER_USE = true;

    protected const bool DEFAULT_DEVICE_CODE_GRANT_ENABLED = true;

    protected const string DEFAULT_SCOPE = '';

    protected const array DEFAULT_SCOPES = [];

    protected const bool DEFAULT_IGNORE_CSRF_TOKEN = false;

    /** @var class-string<AccessTokenEntity> */
    protected const string DEFAULT_ACCESS_TOKEN_ENTITY = AccessTokenEntity::class;

    /** @var class-string<AuthCode> */
    protected const string DEFAULT_AUTH_CODE_MODEL = AuthCode::class;

    /** @var class-string<DeviceCode> */
    protected const string DEFAULT_DEVICE_CODE_MODEL = DeviceCode::class;

    /** @var class-string<Client> */
    protected const string DEFAULT_CLIENT_MODEL = Client::class;

    /** @var class-string<Token> */
    protected const string DEFAULT_TOKEN_MODEL = Token::class;

    /** @var class-string<RefreshToken> */
    protected const string DEFAULT_REFRESH_TOKEN_MODEL = RefreshToken::class;

    /** @var class-string<RefreshTokenFamily> */
    protected const string DEFAULT_REFRESH_TOKEN_FAMILY_MODEL = RefreshTokenFamily::class;

    /** @var class-string<SigningKey> */
    protected const string DEFAULT_SIGNING_KEY_MODEL = SigningKey::class;

    protected const bool DEFAULT_UNSERIALIZES_COOKIES = false;

    protected const bool DEFAULT_DECRYPTS_COOKIES = true;

    protected const bool DEFAULT_WITH_INHERITED_SCOPES = false;

    protected const bool DEFAULT_REGISTERS_ROUTES = true;

    protected const array DEFAULT_INHERITED_GRANT_ATTRIBUTES = [];

    /**
     * Indicates if Passport should validate the permissions of its encryption keys.
     */
    public static bool $validateKeyPermissions = self::DEFAULT_VALIDATE_KEY_PERMISSIONS;

    /**
     * Indicates if the refresh token should be revoked after use.
     */
    public static bool $revokeRefreshTokenAfterUse = self::DEFAULT_REVOKE_REFRESH_TOKEN_AFTER_USE;

    /**
     * Indicates if the device authorization grant type is enabled.
     */
    public static bool $deviceCodeGrantEnabled = self::DEFAULT_DEVICE_CODE_GRANT_ENABLED;

    // Implicit and password grants are omitted; use authorization code with PKCE or device authorization.

    /**
     * The default scope.
     */
    public static string $defaultScope = self::DEFAULT_SCOPE;

    /**
     * All of the scopes defined for the application.
     *
     * @var array<string, string>
     */
    public static array $scopes = self::DEFAULT_SCOPES;

    /**
     * The interval when access tokens expire.
     */
    public static ?DateInterval $tokensExpireIn = null;

    /**
     * The date when refresh tokens expire.
     */
    public static ?DateInterval $refreshTokensExpireIn = null;

    /**
     * The date when personal access tokens expire.
     */
    public static ?DateInterval $personalAccessTokensExpireIn = null;

    /**
     * The interval when client credentials tokens expire.
     */
    public static ?DateInterval $clientCredentialsTokensExpireIn = null;

    /**
     * The name for API token cookies.
     */
    public static string $cookie = self::DEFAULT_COOKIE;

    /**
     * Indicates if Passport should ignore incoming CSRF tokens.
     */
    public static bool $ignoreCsrfToken = self::DEFAULT_IGNORE_CSRF_TOKEN;

    /**
     * The storage location of the encryption keys.
     */
    public static ?string $keyPath = null;

    /**
     * The access token entity class name.
     *
     * @var class-string<AccessTokenEntity>
     */
    public static string $accessTokenEntity = self::DEFAULT_ACCESS_TOKEN_ENTITY;

    /**
     * The auth code model class name.
     *
     * @var class-string<AuthCode>
     */
    public static string $authCodeModel = self::DEFAULT_AUTH_CODE_MODEL;

    /**
     * The device code model class name.
     *
     * @var class-string<DeviceCode>
     */
    public static string $deviceCodeModel = self::DEFAULT_DEVICE_CODE_MODEL;

    /**
     * The client model class name.
     *
     * @var class-string<Client>
     */
    public static string $clientModel = self::DEFAULT_CLIENT_MODEL;

    // Client IDs always use UUIDs; the pre-13 integer-ID toggle is omitted.

    /**
     * The token model class name.
     *
     * @var class-string<Token>
     */
    public static string $tokenModel = self::DEFAULT_TOKEN_MODEL;

    /**
     * The refresh token model class name.
     *
     * @var class-string<RefreshToken>
     */
    public static string $refreshTokenModel = self::DEFAULT_REFRESH_TOKEN_MODEL;

    /**
     * The refresh token family model class name.
     *
     * @var class-string<RefreshTokenFamily>
     */
    public static string $refreshTokenFamilyModel = self::DEFAULT_REFRESH_TOKEN_FAMILY_MODEL;

    /**
     * The signing key model class name.
     *
     * @var class-string<SigningKey>
     */
    public static string $signingKeyModel = self::DEFAULT_SIGNING_KEY_MODEL;

    /**
     * Indicate if Passport should unserialize cookies.
     */
    public static bool $unserializesCookies = self::DEFAULT_UNSERIALIZES_COOKIES;

    /**
     * Indicates if Passport should decrypt cookies.
     */
    public static bool $decryptsCookies = self::DEFAULT_DECRYPTS_COOKIES;

    /**
     * The callback that should be used to generate JWT encryption keys.
     *
     * @var null|(Closure(Encrypter): string)
     */
    public static ?Closure $tokenEncryptionKeyCallback = null;

    /**
     * Indicates the scope should inherit its parent scope.
     */
    public static bool $withInheritedScopes = self::DEFAULT_WITH_INHERITED_SCOPES;

    /**
     * The authorization server response type.
     */
    public static ?ResponseTypeInterface $authorizationServerResponseType = null;

    /**
     * Indicates if Passport routes will be registered.
     */
    public static bool $registersRoutes = self::DEFAULT_REGISTERS_ROUTES;

    // Deprecated JSON API routes are omitted; applications define their own management routes.

    /**
     * The callback used to authenticate access tokens.
     *
     * @var null|(Closure(Token, bool): bool)
     */
    public static ?Closure $accessTokenAuthenticationCallback = null;

    /**
     * The callback used to authenticate clients.
     *
     * @var null|(Closure(Client): bool)
     */
    public static ?Closure $clientAuthenticationCallback = null;

    /**
     * The grant attributes inherited by issued credentials.
     *
     * @var list<string>
     */
    public static array $inheritedGrantAttributes = self::DEFAULT_INHERITED_GRANT_ATTRIBUTES;

    /**
     * The callback used to bind API cookies to the current request.
     *
     * @var null|(Closure(Request): ?string)
     */
    public static ?Closure $cookieBindingCallback = null;

    /**
     * The callback used to capture authorization attributes.
     *
     * @var null|(Closure(Request, Authenticatable): array<string, mixed>)
     */
    public static ?Closure $authorizationAttributesCallback = null;

    /**
     * The callback used to resolve additional authorization prompts.
     *
     * @var null|(Closure(Request, AuthorizationRequestInterface): list<string>)
     */
    public static ?Closure $resolvePromptsCallback = null;

    /**
     * The callback used to add access token claims.
     *
     * @var null|(Closure(Token): array<string, mixed>)
     */
    public static ?Closure $accessTokenClaimsCallback = null;

    /**
     * The callback used to determine whether a refresh token may be issued.
     *
     * @var null|(Closure(Token): bool)
     */
    public static ?Closure $issueRefreshTokensCallback = null;

    // The deprecated setDefaultScope() alias is omitted; use defaultScopes().

    /**
     * Set or get the default scopes.
     *
     * Boot-only when setting. The scopes persist for the worker lifetime and affect every request.
     *
     * @param null|string|string[] $scopes
     * @return string[]
     */
    public static function defaultScopes(array|string|null $scopes = null): array
    {
        if (! is_null($scopes)) {
            static::$defaultScope = is_array($scopes) ? implode(' ', $scopes) : $scopes;
        }

        return static::$defaultScope ? explode(' ', static::$defaultScope) : [];
    }

    /**
     * Return the scopes in the given list that are actually defined scopes for the application.
     *
     * @param string[] $scopes
     * @return string[]
     */
    public static function validScopes(array $scopes): array
    {
        return array_values(array_unique(array_intersect($scopes, array_keys(static::configuredScopes()))));
    }

    /**
     * Get all of the defined scope IDs.
     *
     * @return string[]
     */
    public static function scopeIds(): array
    {
        return static::scopes()->pluck('id')->values()->all();
    }

    /**
     * Determine if the given scope has been defined.
     */
    public static function hasScope(string $id): bool
    {
        return $id === '*' || array_key_exists($id, static::configuredScopes());
    }

    /**
     * Get all of the scopes defined for the application.
     *
     * @return Collection<int, Scope>
     */
    public static function scopes(): Collection
    {
        return collect(static::configuredScopes())->map(
            fn (string $description, string $id): Scope => new Scope($id, $description)
        )->values();
    }

    /**
     * Get all of the scopes matching the given IDs.
     *
     * @param string[] $ids
     * @return Scope[]
     */
    public static function scopesFor(array $ids): array
    {
        $scopes = static::configuredScopes();

        return collect($ids)->map(
            fn (string $id): ?Scope => isset($scopes[$id]) ? new Scope($id, $scopes[$id]) : null
        )->filter()->values()->all();
    }

    /**
     * Get the global scopes and any scopes belonging to the current server.
     *
     * @return array<string, string>
     */
    protected static function configuredScopes(): array
    {
        return app()->bound(ServerSettingsResolver::class)
            ? [...static::$scopes, ...app(ServerFactory::class)->settings()->scopes]
            : static::$scopes;
    }

    /**
     * Define the scopes for the application.
     *
     * Boot-only. The catalog persists in a static property and affects every request on the worker.
     *
     * @param array<string, string> $scopes
     */
    public static function tokensCan(array $scopes): void
    {
        static::$scopes = $scopes;
    }

    /**
     * Get or set when access tokens expire.
     *
     * Boot-only when setting. Cached servers retain this lifetime across requests.
     */
    public static function tokensExpireIn(DateTimeInterface|DateInterval|null $date = null): DateInterval
    {
        if (is_null($date)) {
            return static::$tokensExpireIn ??= new DateInterval('P1Y');
        }

        return static::$tokensExpireIn = $date instanceof DateTimeInterface
            ? Date::now()->diff($date)
            : $date;
    }

    /**
     * Get or set when refresh tokens expire.
     *
     * Boot-only when setting. Cached servers retain this lifetime across requests.
     */
    public static function refreshTokensExpireIn(DateTimeInterface|DateInterval|null $date = null): DateInterval
    {
        if (is_null($date)) {
            return static::$refreshTokensExpireIn ??= new DateInterval('P1Y');
        }

        return static::$refreshTokensExpireIn = $date instanceof DateTimeInterface
            ? Date::now()->diff($date)
            : $date;
    }

    /**
     * Get or set when personal access tokens expire.
     *
     * Boot-only when setting. Cached servers retain this lifetime across requests.
     */
    public static function personalAccessTokensExpireIn(DateTimeInterface|DateInterval|null $date = null): DateInterval
    {
        if (is_null($date)) {
            return static::$personalAccessTokensExpireIn ??= new DateInterval('P1Y');
        }

        return static::$personalAccessTokensExpireIn = $date instanceof DateTimeInterface
            ? Date::now()->diff($date)
            : $date;
    }

    /**
     * Get or set when client credentials grant tokens expire.
     *
     * Boot-only when setting. Cached servers retain this lifetime across requests.
     */
    public static function clientCredentialsTokensExpireIn(DateTimeInterface|DateInterval|null $date = null): DateInterval
    {
        if (is_null($date)) {
            return static::$clientCredentialsTokensExpireIn ?? static::tokensExpireIn();
        }

        return static::$clientCredentialsTokensExpireIn = $date instanceof DateTimeInterface
            ? Date::now()->diff($date)
            : $date;
    }

    /**
     * Get or set the name for API token cookies.
     *
     * Boot-only when setting. The name persists in a static property and affects every request on the worker.
     */
    public static function cookie(?string $cookie = null): string
    {
        if (is_null($cookie)) {
            return static::$cookie;
        }

        return static::$cookie = $cookie;
    }

    /**
     * Indicate that Passport should ignore incoming CSRF tokens.
     *
     * Boot-only. This policy persists in a static property and affects every request on the worker.
     */
    public static function ignoreCsrfToken(bool $ignoreCsrfToken = true): void
    {
        static::$ignoreCsrfToken = $ignoreCsrfToken;
    }

    /**
     * Set the current user for the application with the given scopes.
     *
     * Tests only. This replaces the coroutine's authenticated user and default guard with test state.
     *
     * @param OAuthenticatable $user
     * @param string[] $scopes
     * @return OAuthenticatable
     */
    public static function actingAs(
        Authenticatable $user,
        array $scopes = [],
        ?string $guard = self::DEFAULT_GUARD,
        ?Client $client = null,
    ): Authenticatable {
        $token = new AccessToken([
            'oauth_client_id' => $client?->getKey(),
            'oauth_user_id' => $user->getAuthIdentifier(),
            'oauth_scopes' => $scopes,
        ]);

        $user->withAccessToken($token);

        if (isset($user->wasRecentlyCreated) && $user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        app('auth')->guard($guard)->setUser($user);

        app('auth')->shouldUse($guard);

        return $user;
    }

    /**
     * Set the current client for the application with the given scopes.
     *
     * Tests only. This installs a resource-server mock and authenticated client in the coroutine's context.
     *
     * @param string[] $scopes
     */
    public static function actingAsClient(Client $client, array $scopes = [], ?string $guard = self::DEFAULT_GUARD): Client
    {
        $mock = Mockery::mock(ResourceServer::class);
        $mock->shouldReceive('validateAuthenticatedRequest')->andReturnUsing(
            fn (ServerRequestInterface $request) => $request
                ->withAttribute('oauth_client_id', (string) $client->getKey())
                ->withAttribute('oauth_scopes', $scopes)
                ->withAttribute('oauth_user_id', (string) $client->getKey())
        );

        CoroutineContext::set(ServerFactory::RESOURCE_SERVER_CONTEXT_KEY, $mock);

        app('auth')->guard($guard)->setClient($client);

        app('auth')->shouldUse($guard);

        return $client;
    }

    /**
     * Get the provider for personal access clients without an explicit provider.
     */
    public static function defaultProvider(): ?string
    {
        return app(AuthManager::class)->getUserProviderName(static::DEFAULT_GUARD);
    }

    /**
     * Set the storage location of the encryption keys.
     *
     * Boot-only. Cached servers retain their loaded keys for the worker lifetime.
     */
    public static function loadKeysFrom(string $path): void
    {
        static::$keyPath = $path;
    }

    /**
     * Get the location of the encryption keys.
     */
    public static function keyPath(string $file): string
    {
        $file = ltrim($file, '/\\');

        return isset(static::$keyPath)
            ? rtrim(static::$keyPath, '/\\') . DIRECTORY_SEPARATOR . $file
            : storage_path($file);
    }

    /**
     * Set the access token entity class name.
     *
     * Boot-only. The entity class persists in a static property and affects every issuance on the worker.
     *
     * @param class-string<AccessTokenEntity> $accessTokenEntity
     */
    public static function useAccessTokenEntity(string $accessTokenEntity): void
    {
        static::$accessTokenEntity = $accessTokenEntity;
    }

    /**
     * Set the auth code model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<AuthCode> $authCodeModel
     */
    public static function useAuthCodeModel(string $authCodeModel): void
    {
        static::$authCodeModel = $authCodeModel;
    }

    /**
     * Get the auth code model class name.
     *
     * @return class-string<AuthCode>
     */
    public static function authCodeModel(): string
    {
        return static::$authCodeModel;
    }

    /**
     * Get a new auth code model instance.
     */
    public static function authCode(): AuthCode
    {
        return new static::$authCodeModel;
    }

    /**
     * Set the default ResponseType that should be used by the authorization server.
     *
     * Boot-only. Cached servers retain this response prototype for the worker lifetime.
     */
    public static function useAuthorizationServerResponseType(?ResponseTypeInterface $authorizationServerResponseType): void
    {
        static::$authorizationServerResponseType = $authorizationServerResponseType;
    }

    /**
     * Set the device code model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<DeviceCode> $deviceCodeModel
     */
    public static function useDeviceCodeModel(string $deviceCodeModel): void
    {
        static::$deviceCodeModel = $deviceCodeModel;
    }

    /**
     * Get the device code model class name.
     *
     * @return class-string<DeviceCode>
     */
    public static function deviceCodeModel(): string
    {
        return static::$deviceCodeModel;
    }

    /**
     * Get a new device code model instance.
     */
    public static function deviceCode(): DeviceCode
    {
        return new static::$deviceCodeModel;
    }

    /**
     * Set the client model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<Client> $clientModel
     */
    public static function useClientModel(string $clientModel): void
    {
        static::$clientModel = $clientModel;
    }

    /**
     * Get the client model class name.
     *
     * @return class-string<Client>
     */
    public static function clientModel(): string
    {
        return static::$clientModel;
    }

    /**
     * Get a new client model instance.
     */
    public static function client(): Client
    {
        return new static::$clientModel;
    }

    /**
     * Set the token model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<Token> $tokenModel
     */
    public static function useTokenModel(string $tokenModel): void
    {
        static::$tokenModel = $tokenModel;
    }

    /**
     * Get the token model class name.
     *
     * @return class-string<Token>
     */
    public static function tokenModel(): string
    {
        return static::$tokenModel;
    }

    /**
     * Get a new access token model instance.
     */
    public static function token(): Token
    {
        return new static::$tokenModel;
    }

    /**
     * Set the refresh token model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<RefreshToken> $refreshTokenModel
     */
    public static function useRefreshTokenModel(string $refreshTokenModel): void
    {
        static::$refreshTokenModel = $refreshTokenModel;
    }

    /**
     * Get the refresh token model class name.
     *
     * @return class-string<RefreshToken>
     */
    public static function refreshTokenModel(): string
    {
        return static::$refreshTokenModel;
    }

    /**
     * Get a new refresh token model instance.
     */
    public static function refreshToken(): RefreshToken
    {
        return new static::$refreshTokenModel;
    }

    /**
     * Set the refresh token family model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<RefreshTokenFamily> $refreshTokenFamilyModel
     */
    public static function useRefreshTokenFamilyModel(string $refreshTokenFamilyModel): void
    {
        static::$refreshTokenFamilyModel = $refreshTokenFamilyModel;
    }

    /**
     * Get the refresh token family model class name.
     *
     * @return class-string<RefreshTokenFamily>
     */
    public static function refreshTokenFamilyModel(): string
    {
        return static::$refreshTokenFamilyModel;
    }

    /**
     * Get a new refresh token family model instance.
     */
    public static function refreshTokenFamily(): RefreshTokenFamily
    {
        return new static::$refreshTokenFamilyModel;
    }

    /**
     * Set the signing key model class name.
     *
     * Boot-only. The model class persists in a static property and affects every request on the worker.
     *
     * @param class-string<SigningKey> $signingKeyModel
     */
    public static function useSigningKeyModel(string $signingKeyModel): void
    {
        static::$signingKeyModel = $signingKeyModel;
    }

    /**
     * Get the signing key model class name.
     *
     * @return class-string<SigningKey>
     */
    public static function signingKeyModel(): string
    {
        return static::$signingKeyModel;
    }

    /**
     * Get a new signing key model instance.
     */
    public static function signingKey(): SigningKey
    {
        return new static::$signingKeyModel;
    }

    /**
     * Specify an additional access token authentication check.
     *
     * Boot-only. The callback persists in a static property and runs for every bearer authentication.
     *
     * @param Closure(Token, bool): bool $callback
     */
    public static function authenticateAccessTokensUsing(Closure $callback): void
    {
        static::$accessTokenAuthenticationCallback = $callback;
    }

    /**
     * Specify an additional client authentication check.
     *
     * Boot-only. The callback persists in a static property and runs for every client authentication.
     *
     * @param Closure(Client): bool $callback
     */
    public static function authenticateClientsUsing(Closure $callback): void
    {
        static::$clientAuthenticationCallback = $callback;
    }

    /**
     * Specify grant attributes to carry into issued tokens.
     *
     * Boot-only. The attribute list persists in a static property and affects every issuance on the worker.
     *
     * @param list<string> $attributes
     */
    public static function inheritGrantAttributes(array $attributes): void
    {
        static::$inheritedGrantAttributes = $attributes;
    }

    /**
     * Specify the binding included in and checked against API cookies.
     *
     * Boot-only. The callback persists in a static property and affects cookies across all requests.
     *
     * @param Closure(Request): ?string $callback
     */
    public static function bindCookiesUsing(Closure $callback): void
    {
        static::$cookieBindingCallback = $callback;
    }

    /**
     * Specify attributes to save with an approved authorization.
     *
     * Boot-only. The callback persists in a static property and affects every authorization on the worker.
     *
     * @param Closure(Request, Authenticatable): array<string, mixed> $callback
     */
    public static function authorizationAttributesUsing(Closure $callback): void
    {
        static::$authorizationAttributesCallback = $callback;
    }

    /**
     * Specify additional interactions required for an authorization request.
     *
     * Boot-only. The callback persists in a static property and affects every authorization on the worker.
     *
     * @param Closure(Request, AuthorizationRequestInterface): list<string> $callback
     */
    public static function resolvePromptsUsing(Closure $callback): void
    {
        static::$resolvePromptsCallback = $callback;
    }

    /**
     * Specify additional claims for issued access tokens.
     *
     * Boot-only. The callback persists in a static property and affects every issuance on the worker.
     *
     * @param Closure(Token): array<string, mixed> $callback
     */
    public static function accessTokenClaimsUsing(Closure $callback): void
    {
        static::$accessTokenClaimsCallback = $callback;
    }

    /**
     * Specify whether an access token may receive a refresh token.
     *
     * Boot-only. The callback persists in a static property and affects every issuance on the worker.
     *
     * @param Closure(Token): bool $callback
     */
    public static function issueRefreshTokensUsing(Closure $callback): void
    {
        static::$issueRefreshTokensCallback = $callback;
    }

    /**
     * Specify the callback that should be invoked to generate encryption keys for encrypting JWT tokens.
     *
     * Boot-only. The callback supplies random key material that cached servers retain for the worker lifetime.
     *
     * @param null|(Closure(Encrypter): string) $callback
     */
    public static function encryptTokensUsing(?Closure $callback): void
    {
        static::$tokenEncryptionKeyCallback = $callback;
    }

    /**
     * Generate an encryption key for encrypting JWT tokens.
     */
    public static function tokenEncryptionKey(Encrypter $encrypter): string
    {
        return is_callable(static::$tokenEncryptionKeyCallback)
            ? (static::$tokenEncryptionKeyCallback)($encrypter)
            : $encrypter->getKey();
    }

    /**
     * Register the views for Passport using conventional names under the given namespace.
     *
     * Boot-only. The response bindings persist in the worker's container and affect subsequent requests.
     */
    public static function viewNamespace(string $namespace): void
    {
        static::viewPrefix($namespace . '::');
    }

    /**
     * Register the views for Passport using conventional names under the given prefix.
     *
     * Boot-only. The response bindings persist in the worker's container and affect subsequent requests.
     */
    public static function viewPrefix(string $prefix): void
    {
        $prefix = rtrim($prefix, '.');

        static::authorizationView($prefix . '.authorize');
        static::deviceAuthorizationView($prefix . '.device.authorize');
        static::deviceUserCodeView($prefix . '.device.user-code');
    }

    /**
     * Specify which view should be used as the authorization view.
     *
     * Boot-only. The response binding persists in the worker's container and affects subsequent requests.
     *
     * @param (Closure(array<string, mixed>): mixed)|string $view
     */
    public static function authorizationView(Closure|string $view): void
    {
        app()->bind(AuthorizationViewResponse::class, fn () => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the device authorization view.
     *
     * Boot-only. The response binding persists in the worker's container and affects subsequent requests.
     *
     * @param (Closure(array<string, mixed>): mixed)|string $view
     */
    public static function deviceAuthorizationView(Closure|string $view): void
    {
        app()->bind(DeviceAuthorizationViewResponse::class, fn () => new SimpleViewResponse($view));
    }

    /**
     * Specify which view should be used as the device user code view.
     *
     * Boot-only. The response binding persists in the worker's container and affects subsequent requests.
     *
     * @param (Closure(array<string, mixed>): mixed)|string $view
     */
    public static function deviceUserCodeView(Closure|string $view): void
    {
        app()->bind(DeviceUserCodeViewResponse::class, fn () => new SimpleViewResponse($view));
    }

    /**
     * Configure Passport to not register its routes.
     *
     * Boot-only. This changes route registration for the worker's application.
     */
    public static function ignoreRoutes(): void
    {
        static::$registersRoutes = false;
    }

    /**
     * Instruct Passport to enable cookie serialization.
     *
     * Boot-only. The policy persists in a static property and affects every request on the worker.
     */
    public static function withCookieSerialization(): void
    {
        static::$unserializesCookies = true;
    }

    /**
     * Instruct Passport to disable cookie serialization.
     *
     * Boot-only. The policy persists in a static property and affects every request on the worker.
     */
    public static function withoutCookieSerialization(): void
    {
        static::$unserializesCookies = false;
    }

    /**
     * Instruct Passport to enable cookie encryption.
     *
     * Boot-only. The policy persists in a static property and affects every request on the worker.
     */
    public static function withCookieEncryption(): void
    {
        static::$decryptsCookies = true;
    }

    /**
     * Instruct Passport to disable cookie encryption.
     *
     * Boot-only. The policy persists in a static property and affects every request on the worker.
     */
    public static function withoutCookieEncryption(): void
    {
        static::$decryptsCookies = false;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$validateKeyPermissions = self::DEFAULT_VALIDATE_KEY_PERMISSIONS;
        static::$revokeRefreshTokenAfterUse = self::DEFAULT_REVOKE_REFRESH_TOKEN_AFTER_USE;
        static::$deviceCodeGrantEnabled = self::DEFAULT_DEVICE_CODE_GRANT_ENABLED;
        static::$defaultScope = self::DEFAULT_SCOPE;
        static::$scopes = self::DEFAULT_SCOPES;
        static::$tokensExpireIn = null;
        static::$refreshTokensExpireIn = null;
        static::$personalAccessTokensExpireIn = null;
        static::$clientCredentialsTokensExpireIn = null;
        static::$cookie = self::DEFAULT_COOKIE;
        static::$ignoreCsrfToken = self::DEFAULT_IGNORE_CSRF_TOKEN;
        static::$keyPath = null;
        static::$accessTokenEntity = self::DEFAULT_ACCESS_TOKEN_ENTITY;
        static::$authCodeModel = self::DEFAULT_AUTH_CODE_MODEL;
        static::$deviceCodeModel = self::DEFAULT_DEVICE_CODE_MODEL;
        static::$clientModel = self::DEFAULT_CLIENT_MODEL;
        static::$tokenModel = self::DEFAULT_TOKEN_MODEL;
        static::$refreshTokenModel = self::DEFAULT_REFRESH_TOKEN_MODEL;
        static::$refreshTokenFamilyModel = self::DEFAULT_REFRESH_TOKEN_FAMILY_MODEL;
        static::$signingKeyModel = self::DEFAULT_SIGNING_KEY_MODEL;
        static::$unserializesCookies = self::DEFAULT_UNSERIALIZES_COOKIES;
        static::$decryptsCookies = self::DEFAULT_DECRYPTS_COOKIES;
        static::$tokenEncryptionKeyCallback = null;
        static::$withInheritedScopes = self::DEFAULT_WITH_INHERITED_SCOPES;
        static::$authorizationServerResponseType = null;
        static::$registersRoutes = self::DEFAULT_REGISTERS_ROUTES;
        static::$accessTokenAuthenticationCallback = null;
        static::$clientAuthenticationCallback = null;
        static::$inheritedGrantAttributes = self::DEFAULT_INHERITED_GRANT_ATTRIBUTES;
        static::$cookieBindingCallback = null;
        static::$authorizationAttributesCallback = null;
        static::$resolvePromptsCallback = null;
        static::$accessTokenClaimsCallback = null;
        static::$issueRefreshTokensCallback = null;
    }
}
