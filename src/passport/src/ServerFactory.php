<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DateInterval;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Encoding;
use Defuse\Crypto\Key;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Http\Request;
use Hypervel\Passport\Bridge\AuthCodeGrant;
use Hypervel\Passport\Bridge\AuthorizationServer;
use Hypervel\Passport\Bridge\BearerRequestFactory;
use Hypervel\Passport\Bridge\BearerTokenValidator;
use Hypervel\Passport\Bridge\ClientCredentialsGrant;
use Hypervel\Passport\Bridge\DeviceCodeGrant;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Bridge\PersonalAccessBearerTokenResponse;
use Hypervel\Passport\Bridge\PersonalAccessGrant;
use Hypervel\Passport\Bridge\RefreshTokenGrant;
use Hypervel\Passport\Bridge\RefreshTokenRepository;
use Hypervel\Passport\Bridge\SigningCryptKey;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Enums\KeyStore;
use Hypervel\Routing\Router;
use Hypervel\Support\CarbonImmutable;
use InvalidArgumentException;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

class ServerFactory
{
    public const string RESOURCE_SERVER_CONTEXT_KEY = '__passport.resource_server';

    protected const string BEARER_CONTEXT_KEY = '__passport.bearer';

    protected ?ServerEntry $defaultEntry = null;

    /** @var array<string, ServerEntry> */
    protected array $entries = [];

    protected int $nextKeyRefresh = 0;

    /** @var list<string> */
    protected array $storedKeyIds = [];

    protected ?Key $encryptionKey = null;

    protected ?string $cookieSigningKey = null;

    protected int $hashSeed;

    /**
     * Create the worker's server and key cache.
     */
    public function __construct(
        protected Container $app,
        protected Repository $config,
        protected Encrypter $encrypter,
        protected BearerRequestFactory $bearerRequests,
    ) {
        $this->hashSeed = random_int(1, PHP_INT_MAX);
    }

    /**
     * Get the authorization server for the current settings and signing key.
     */
    public function authorizationServer(): AuthorizationServer
    {
        $entry = $this->entry();
        $key = TokenIssuance::current()->signingKey ?? $entry->signingKey(CarbonImmutable::now());
        $identifier = $key->getKeyId();

        if (isset($entry->authorizationServers[$identifier])) {
            return $entry->authorizationServers[$identifier];
        }

        $settings = $entry->settings;
        $server = $this->makeAuthorizationServer($key);
        $server->enableGrantType($this->makeAuthCodeGrant($settings), $settings->tokensExpireIn ?? Passport::tokensExpireIn());
        $server->enableGrantType($this->makeRefreshTokenGrant($settings), $settings->tokensExpireIn ?? Passport::tokensExpireIn());
        $server->enableGrantType(new ClientCredentialsGrant, $settings->clientCredentialsTokensExpireIn ?? Passport::clientCredentialsTokensExpireIn());

        // Implicit and password grants are omitted; use authorization code with PKCE.
        if (Passport::$deviceCodeGrantEnabled && $this->app->make(Router::class)->has('passport.device')) {
            $server->enableGrantType($this->makeDeviceCodeGrant($settings), $settings->tokensExpireIn ?? Passport::tokensExpireIn());
        }

        return $entry->authorizationServers[$identifier] = $server;
    }

    /**
     * Get the personal access server for the current settings and signing key.
     */
    public function personalAccessServer(): AuthorizationServer
    {
        $entry = $this->entry();
        $key = TokenIssuance::current()->signingKey ?? $entry->signingKey(CarbonImmutable::now());
        $identifier = $key->getKeyId();

        if (! isset($entry->personalAccessServers[$identifier])) {
            $server = $this->makeAuthorizationServer($key, new PersonalAccessBearerTokenResponse, personalAccess: true);
            $server->enableGrantType(new PersonalAccessGrant, $entry->settings->personalAccessTokensExpireIn ?? Passport::personalAccessTokensExpireIn());
            $entry->personalAccessServers[$identifier] = $server;
        }

        return $entry->personalAccessServers[$identifier];
    }

    /**
     * Get the current server settings, pinned during an issuance.
     */
    public function settings(): ServerSettings
    {
        return $this->entry()->settings;
    }

    /**
     * Get the signing key and retain it for the supplied token lifetime.
     */
    public function signingKey(DateInterval $lifetime): SigningCryptKey
    {
        if ($issuance = TokenIssuance::current()) {
            $issuance->retainSigningKey(CarbonImmutable::now()->add($lifetime), $lifetime);

            return $issuance->signingKey;
        }

        return TokenIssuance::run($this, function () use ($lifetime): SigningCryptKey {
            return $this->signingKey($lifetime);
        });
    }

    /**
     * Get the public JSON Web Key Set for the current settings.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return $this->entry()->jwks;
    }

    /**
     * Get the app-wide cookie signing key.
     */
    public function cookieKey(): string
    {
        $this->initializeEncryptionKeys();

        return $this->cookieSigningKey;
    }

    /**
     * Decrypt a grant credential with the same key used during issuance.
     *
     * @internal shared with token management; callers validate the payload's credential type
     */
    public function decryptToken(#[SensitiveParameter] string $token): string
    {
        $this->initializeEncryptionKeys();

        return Crypto::decrypt($token, $this->encryptionKey);
    }

    /**
     * Derive separate keys for grant encryption and API cookies once per worker.
     */
    protected function initializeEncryptionKeys(): void
    {
        if ($this->encryptionKey !== null) {
            return;
        }

        $material = Passport::tokenEncryptionKey($this->encrypter);
        // A Key avoids League's per-operation PBKDF2 password stretching; this material is already random.
        $this->encryptionKey = Key::loadFromAsciiSafeString(Encoding::saveBytesToChecksummedAsciiSafeString(
            Key::KEY_CURRENT_VERSION,
            hash_hkdf('sha256', $material, Key::KEY_BYTE_SIZE, 'hypervel-passport'),
        ));
        $this->cookieSigningKey = hash_hkdf('sha256', $material, 32, 'hypervel-passport-cookie');
    }

    /**
     * Create and configure an instance of the Auth Code grant.
     */
    protected function makeAuthCodeGrant(ServerSettings $settings): AuthCodeGrant
    {
        return tap($this->buildAuthCodeGrant($settings), function (AuthCodeGrant $grant) use ($settings): void {
            $grant->setRefreshTokenTTL($settings->refreshTokensExpireIn ?? Passport::refreshTokensExpireIn());
        });
    }

    /**
     * Build the Auth Code grant instance.
     */
    protected function buildAuthCodeGrant(ServerSettings $settings): AuthCodeGrant
    {
        return new AuthCodeGrant(
            $this->app->make(Bridge\AuthCodeRepository::class),
            $this->app->make(Bridge\RefreshTokenRepository::class),
            new DateInterval('PT10M')
        );
    }

    /**
     * Create and configure a Refresh Token grant instance.
     */
    protected function makeRefreshTokenGrant(ServerSettings $settings): RefreshTokenGrant
    {
        return tap(new RefreshTokenGrant(
            $this->app->make(RefreshTokenRepository::class)
        ), function (RefreshTokenGrant $grant) use ($settings): void {
            $grant->setRefreshTokenTTL($settings->refreshTokensExpireIn ?? Passport::refreshTokensExpireIn());
        });
    }

    /**
     * Create and configure an instance of the Device Code grant.
     */
    protected function makeDeviceCodeGrant(ServerSettings $settings): DeviceCodeGrant
    {
        return tap(new DeviceCodeGrant(
            $this->app->make(DeviceCodeRepository::class),
            $this->app->make(RefreshTokenRepository::class),
            new DateInterval('PT10M'),
            $this->app->make(UrlGenerator::class),
            5
        ), function (DeviceCodeGrant $grant) use ($settings): void {
            $grant->setRefreshTokenTTL($settings->refreshTokensExpireIn ?? Passport::refreshTokensExpireIn());
            $grant->setIncludeVerificationUriComplete(true);
            $grant->setIntervalVisibility(true);
        });
    }

    /**
     * Make the authorization service instance.
     */
    protected function makeAuthorizationServer(SigningCryptKey $key, ?ResponseTypeInterface $responseType = null, bool $personalAccess = false): AuthorizationServer
    {
        $this->initializeEncryptionKeys();

        return tap(new AuthorizationServer(
            $this->app->make(Bridge\ClientRepository::class),
            $this->app->make(Bridge\AccessTokenRepository::class),
            $this->app->make(Bridge\ScopeRepository::class),
            $key,
            $this->encryptionKey,
            $responseType ?? Passport::$authorizationServerResponseType ?? new Bridge\BearerTokenResponse,
            $this,
            $personalAccess,
        ), function (AuthorizationServer $server): void {
            $server->setDefaultScope(Passport::$defaultScope);
            $server->revokeRefreshTokens(Passport::$revokeRefreshTokenAfterUse);
        });
    }

    /**
     * Get the resource server for the current verification keys.
     */
    public function resourceServer(): ResourceServer
    {
        if ($server = CoroutineContext::get(self::RESOURCE_SERVER_CONTEXT_KEY)) {
            return $server;
        }

        $entry = $this->entry();

        if ($entry->resourceServer === null) {
            $repository = $this->app->make(Bridge\AccessTokenRepository::class);
            $publicKey = $entry->verificationKeys[array_key_first($entry->verificationKeys)] ?? null;

            if ($publicKey === null) {
                throw new LogicException('No Passport verification keys are available. Generate or import signing keys first.');
            }

            $entry->resourceServer = new Bridge\ResourceServer(
                $repository,
                new SigningCryptKey($publicKey),
                $this->tokenValidator(),
                $this,
            );
        }

        return $entry->resourceServer;
    }

    /**
     * Get the token verifier shared by resource requests and credential management.
     *
     * @internal does not perform request-specific proof or authorization checks
     */
    public function tokenValidator(): BearerTokenValidator
    {
        $entry = $this->entry();

        return $entry->tokenValidator ??= new BearerTokenValidator(
            $this->app->make(Bridge\AccessTokenRepository::class),
            $entry->verificationKeys,
        );
    }

    /**
     * Share bearer validation across the current request's guards and middleware.
     */
    public function validateAuthenticatedRequest(Request $request, bool $cache = true): ServerRequestInterface
    {
        $server = $this->resourceServer();
        $fingerprint = $cache ? $this->authenticationFingerprint($request) : '';

        if ($cache && ($cached = CoroutineContext::get(self::BEARER_CONTEXT_KEY)) !== null
            && $cached['request'] === $request && $cached['server'] === $server && $cached['fingerprint'] === $fingerprint) {
            $result = $cached['result'];
        } else {
            try {
                $psrRequest = $this->bearerRequests->make($request);
                $result = $server instanceof Bridge\ResourceServer
                    ? $server->validateRequest($psrRequest)
                    : $server->validateAuthenticatedRequest($psrRequest);
            } catch (OAuthServerException $exception) {
                $result = $exception;
            }

            if ($cache) {
                CoroutineContext::set(self::BEARER_CONTEXT_KEY, [
                    'request' => $request,
                    'server' => $server,
                    'fingerprint' => $fingerprint,
                    'result' => $result,
                ]);
            }
        }

        if ($result instanceof OAuthServerException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Identify the request inputs shared by authentication caches.
     *
     * @internal used by the guard and resource-server request cache
     */
    public function authenticationFingerprint(Request $request): string
    {
        $authorization = $request->headers->get('Authorization') ?? '';
        $credentials = BearerRequestFactory::credentials($authorization);
        $inputs = ($credentials['scheme'] ?? null) === 'DPoP'
            ? serialize([
                $authorization, $request->headers->all('DPoP'), $request->getMethod(), $request->getUri(),
            ])
            : $authorization;

        return hash('xxh128', $inputs, options: ['seed' => $this->hashSeed]);
    }

    /**
     * Get the current settings entry without changing a pinned issuance.
     *
     * @internal shared with the issuance context
     */
    public function entry(): ServerEntry
    {
        if ($issuance = TokenIssuance::current()) {
            return $issuance->entry;
        }

        if (! $this->app->bound(ServerSettingsResolver::class)
            || ($identifier = ($resolver = $this->app->make(ServerSettingsResolver::class))->current()) === null) {
            return $this->defaultEntry();
        }

        if (isset($this->entries[$identifier])) {
            $entry = $this->entries[$identifier];
            unset($this->entries[$identifier]);
        } else {
            $entry = $this->makeEntry($resolver->resolve($identifier));
        }

        $this->entries[$identifier] = $entry;

        if (count($this->entries) > $this->config->integer('passport.server_cache_size')) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        return $entry;
    }

    /**
     * Load default keys once, or refresh database keys at the configured interval.
     */
    protected function defaultEntry(): ServerEntry
    {
        if (KeyStore::from($this->config->string('passport.key_store')) === KeyStore::Files) {
            return $this->defaultEntry ??= $this->makeEntry(new ServerSettings(
                $this->fileKeys(),
                issuer: ($issuer = $this->config->get('passport.issuer')) === '' ? null : $issuer,
            ));
        }

        $now = CarbonImmutable::now()->getTimestamp();

        if ($this->defaultEntry === null || $now >= $this->nextKeyRefresh) {
            $keys = $this->app->make(SigningKeyRepository::class)->serverKeys();
            $identifiers = array_map(static fn (StoredServerKey $key): string => $key->id, $keys);

            if ($this->defaultEntry === null || $identifiers !== $this->storedKeyIds) {
                $issuer = $this->config->get('passport.issuer');
                $refreshInterval = $this->config->integer('passport.key_refresh_interval');
                $this->defaultEntry = $this->makeEntry(new ServerSettings(
                    $keys,
                    issuer: $issuer === '' ? null : $issuer,
                    // Bound HTTP staleness for both scheduled activation and emergency revocation.
                    metadataCacheLifetime: min($refreshInterval, $this->config->integer('passport.key_activation_delay') - $refreshInterval),
                ));
                $this->storedKeyIds = $identifiers;
            }

            $this->nextKeyRefresh = $now + $this->config->integer('passport.key_refresh_interval');
        }

        return $this->defaultEntry;
    }

    /**
     * Get the configured file or PEM keys, including retained verification keys.
     *
     * @internal shared with the signing-key repository for file imports
     *
     * @return list<ServerKey>
     */
    public function fileKeys(): array
    {
        $private = $this->config->get('passport.private_key');
        $public = $this->config->get('passport.public_key');

        if ($private === null || $private === '') {
            $path = Passport::keyPath('oauth-private.key');
            $private = is_file($path) ? 'file://' . $path : null;
        } else {
            $private = str_replace('\n', "\n", $private);
        }

        $public = $public === null || $public === ''
            ? 'file://' . Passport::keyPath('oauth-public.key')
            : str_replace('\n', "\n", $public);

        return [
            new ServerKey($public, $private),
            ...array_map(static fn (string $key): ServerKey => new ServerKey($key), $this->config->array('passport.verification_keys')),
        ];
    }

    /**
     * Build a worker-owned entry with validated, parsed key material.
     */
    protected function makeEntry(ServerSettings $settings): ServerEntry
    {
        $ordered = $settings->keys;
        // An undated key is active from the start, before any scheduled replacement.
        usort($ordered, static fn (ServerKey $first, ServerKey $second): int => $second->activatesAt <=> $first->activatesAt);
        $keys = $verificationKeys = $signingKeys = [];

        foreach ($ordered as $key) {
            $public = $this->makeCryptKey('public', $key);
            $parsed = ParsedKey::fromPublicKey($public->getKeyContents());
            $identifier = $parsed->getKeyId();

            if (isset($keys[$identifier])) {
                throw new InvalidArgumentException('Passport server settings must not contain duplicate signing-key identities.');
            }

            $keys[$identifier] = $key;
            $verificationKeys[$identifier] = $parsed;

            if ($key->privateKey !== null) {
                $private = $this->makeCryptKey('private', $key);
                $parsedPrivate = ParsedKey::fromPrivateKey($private->getKeyContents());

                if ($parsedPrivate->getKeyId() !== $identifier) {
                    throw new InvalidArgumentException('Passport private and public keys must belong to the same key pair.');
                }

                $signingKeys[$identifier] = new SigningCryptKey($parsedPrivate);
            }
        }

        return new ServerEntry($settings, $keys, $verificationKeys, $signingKeys);
    }

    /**
     * Create a CryptKey instance.
     */
    protected function makeCryptKey(string $type, ServerKey $key): CryptKey
    {
        return new CryptKey($type === 'private' ? $key->privateKey : $key->publicKey, null, Passport::$validateKeyPermissions);
    }
}
