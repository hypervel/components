<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passport\ClientRepository as PassportClientRepository;
use Hypervel\Passport\Events\AccessTokenCreated;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ResourceRepository;
use Hypervel\Support\CarbonImmutable;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    use PersistsTokens;

    /**
     * Create a new repository instance.
     */
    public function __construct(
        protected Dispatcher $events,
    ) {
    }

    /**
     * Create a new access token entity.
     *
     * {@inheritdoc}
     */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null
    ): AccessTokenEntityInterface {
        if ($issuance = TokenIssuance::current()) {
            $issuance->refreshTokenScopes ??= array_map(
                static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
                $scopes,
            );

            if ($issuance->requestedResources !== null || $issuance->resources !== null) {
                $resources = app(ResourceRepository::class);
                $issuance->audience = $resources->select($issuance->requestedResources, $issuance->resources, $issuance->grant !== null);
                $scopes = $resources->finalizeScopes($scopes, $issuance->audience, $issuance->resources);
            }
        }

        return new Passport::$accessTokenEntity($userIdentifier, $scopes, $clientEntity);
    }

    /**
     * Persist a new access token.
     *
     * {@inheritdoc}
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $issuance = TokenIssuance::current();
        $issuance?->begin();

        if ($issuance?->dpopJkt !== null) {
            $expiresAt = CarbonImmutable::now()->add($issuance->entry->settings->dpopTokensExpireIn ?? Passport::dpopTokensExpireIn());

            if ($expiresAt < $accessTokenEntity->getExpiryDateTime()) {
                $accessTokenEntity->setExpiryDateTime($expiresAt);
            }
        }

        $token = Passport::token()->forceFill([
            ...($issuance?->attributes() ?? []),
            'id' => $id = $accessTokenEntity->getIdentifier(),
            'user_id' => $userId = $accessTokenEntity->getUserIdentifier(),
            'client_id' => $clientId = $accessTokenEntity->getClient()->getIdentifier(),
            'scopes' => $accessTokenEntity->getScopes(),
            'audience' => $issuance?->audience ?: null,
            'dpop_jkt' => $issuance?->dpopJkt,
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ]);

        if ($issuance?->tokenName !== null) {
            $token->name = $issuance->tokenName;
        }

        $this->saveToken($token);
        $issuance?->setAccessToken($token);

        if ($accessTokenEntity instanceof AccessToken) {
            $accessTokenEntity->setToken($token);
        }

        $token->getConnection()->afterCommitOrNow(function () use ($id, $userId, $clientId): void {
            if ($this->events->hasListeners(AccessTokenCreated::class)) {
                $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
            }
        });
    }

    /**
     * Revoke an access token.
     *
     * {@inheritdoc}
     */
    public function revokeAccessToken(string $tokenId): void
    {
        TokenIssuance::current()?->begin();
        $token = Passport::token();

        if ($token->newQuery()->whereKey($tokenId)->where('revoked', false)->update(['revoked' => true])) {
            $token::clearTokenCache($tokenId);
            $token->getConnection()->afterCommitOrNow(function () use ($tokenId): void {
                if ($this->events->hasListeners(AccessTokenRevoked::class)) {
                    $this->events->dispatch(new AccessTokenRevoked($tokenId));
                }
            });
        }
    }

    /**
     * Determine whether an access token may authenticate the request.
     *
     * {@inheritdoc}
     */
    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $token = Passport::tokenModel()::findToken($tokenId);

        if ($token === null) {
            return true;
        }

        $isValid = ! $token->revoked;
        $client = app(PassportClientRepository::class)->findActive($token->client_id);

        if ($callback = Passport::$accessTokenAuthenticationCallback) {
            $isValid = $callback($token, $isValid) && $isValid;
        }

        return ! $isValid || $client === null;
    }
}
