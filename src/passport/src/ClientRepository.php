<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Support\Str;
use RuntimeException;

class ClientRepository
{
    /**
     * Get a client by the given ID.
     */
    public function find(string|int $id): ?Client
    {
        $model = Passport::clientModel();

        return app(PassportCache::class)->findClient($model, $id, static function () use ($model, $id): ?Client {
            $client = new $model;

            if (in_array($client->getKeyName(), $client->uniqueIds(), true)
                && ! (fn () => $this->isValidUniqueId($id))->call($client)) {
                return null;
            }

            // Authentication must observe client revocation and secret changes without replica lag.
            return $client->newQuery()->useWritePdo()->find($id)?->unsetRelations();
        });
    }

    /**
     * Get an active client by the given ID.
     */
    public function findActive(string|int $id): ?Client
    {
        $client = $this->find($id);

        return $client && ! $client->revoked && $this->allowsClient($client) ? $client : null;
    }

    /**
     * Determine whether the current request may use the client.
     */
    protected function allowsClient(Client $client): bool
    {
        return Passport::$clientAuthenticationCallback === null
            || (Passport::$clientAuthenticationCallback)($client);
    }

    // Deprecated user lookup helpers are omitted; use the user's oauthApps() relation.

    /**
     * Get the latest active personal access client for the given user provider.
     *
     * @throws RuntimeException
     */
    public function personalAccessClient(string $provider): Client
    {
        return Passport::client()
            ->newQuery()
            ->where('revoked', false)
            ->whereJsonContains('grant_types', 'personal_access')
            ->where(function (Builder $query) use ($provider): void {
                $query->when($provider === Passport::defaultProvider(), function (Builder $query): void {
                    $query->orWhereNull('provider');
                })->orWhere('provider', $provider);
            })
            ->latest()
            ->lazy(100)
            ->first(fn (Client $client): bool => $this->allowsClient($client))
            ?? throw new RuntimeException(
                "Personal access client not found for '{$provider}' user provider. Please create one."
            );
    }

    /**
     * Store a new client.
     *
     * @param string[] $grantTypes
     * @param string[] $redirectUris
     * @param null|OAuthenticatable $user
     */
    protected function create(
        string $name,
        array $grantTypes,
        array $redirectUris = [],
        ?string $provider = null,
        bool $confidential = true,
        ?Authenticatable $user = null
    ): Client {
        $client = Passport::client();

        $attributes = [
            'name' => $name,
            'secret' => $confidential ? Str::random(40) : null,
            'provider' => $provider,
            'revoked' => false,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
        ];

        return match (true) {
            ! is_null($user) => $user->oauthApps()->forceCreate($attributes),
            default => $client->newQuery()->forceCreate($attributes),
        };
    }

    /**
     * Store a new personal access token client.
     */
    public function createPersonalAccessGrantClient(string $name, ?string $provider = null): Client
    {
        return $this->create($name, ['personal_access'], [], $provider);
    }

    // Password-grant clients are omitted; use authorization code with PKCE or device authorization.

    /**
     * Store a new client credentials grant client.
     */
    public function createClientCredentialsGrantClient(string $name): Client
    {
        return $this->create($name, ['client_credentials']);
    }

    // Implicit-grant clients are omitted; use authorization code with PKCE.

    /**
     * Store a new device authorization grant client.
     *
     * @param null|OAuthenticatable $user
     */
    public function createDeviceAuthorizationGrantClient(
        string $name,
        bool $confidential = true,
        ?Authenticatable $user = null
    ): Client {
        return $this->create(
            $name,
            ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'],
            [],
            null,
            $confidential,
            $user
        );
    }

    /**
     * Store a new authorization code grant client.
     *
     * @param string[] $redirectUris
     * @param null|OAuthenticatable $user
     */
    public function createAuthorizationCodeGrantClient(
        string $name,
        array $redirectUris,
        bool $confidential = true,
        ?Authenticatable $user = null,
        bool $enableDeviceFlow = false
    ): Client {
        $grantTypes = ['authorization_code', 'refresh_token'];

        if ($enableDeviceFlow) {
            $grantTypes[] = 'urn:ietf:params:oauth:grant-type:device_code';
        }

        return $this->create($name, $grantTypes, $redirectUris, null, $confidential, $user);
    }

    // The deprecated update() helper is omitted; update the client model directly.

    /**
     * Regenerate the client secret.
     */
    public function regenerateSecret(Client $client): bool
    {
        return $client->forceFill([
            'secret' => Str::random(40),
        ])->save();
    }

    // The deprecated delete() helper is omitted; use Client::revoke().
}
