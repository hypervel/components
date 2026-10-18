<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passport\Events\RefreshTokenCreated;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken as RefreshTokenModel;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
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
     * Create a refresh token when the issuance policy allows it.
     *
     * {@inheritdoc}
     */
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        $issuance = TokenIssuance::current();

        if (! Passport::$revokeRefreshTokenAfterUse && $issuance?->client !== null
            && ! $issuance->client->isConfidential() && $issuance->dpopJkt === null) {
            return null;
        }

        if (($callback = Passport::$issueRefreshTokensCallback) && ! $callback($issuance?->accessToken)) {
            if ($issuance?->grant instanceof RefreshTokenModel) {
                throw OAuthServerException::invalidGrant();
            }

            return null;
        }

        return new RefreshToken;
    }

    /**
     * Persist a refresh token and its grant family.
     *
     * {@inheritdoc}
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $issuance = TokenIssuance::current();
        $issuance?->begin();
        $token = Passport::refreshToken();

        $persist = function () use ($issuance, $token, $refreshTokenEntity): void {
            $attributes = $issuance?->attributes() ?? [];
            $binding = $issuance?->grant instanceof RefreshTokenModel ? $issuance->grant->dpop_jkt : null;

            if ($binding === null && $issuance?->client !== null && ! $issuance->client->isConfidential()) {
                $binding = $issuance->dpopJkt;
            }

            if ($issuance?->grant instanceof RefreshTokenModel) {
                $familyId = $issuance->grant->family_id;
            } else {
                $family = Passport::refreshTokenFamily()->forceFill(
                    array_intersect_key($attributes, array_flip(['consent_id', ...Passport::$inheritedGrantAttributes]))
                );

                if ($issuance?->refreshTokenFamilyId !== null) {
                    $family->setAttribute($family->getKeyName(), $issuance->refreshTokenFamilyId);
                }

                $this->saveToken($family);
                $familyId = $family->getKey();
            }

            $token->forceFill([
                ...$attributes,
                'id' => $id = $refreshTokenEntity->getIdentifier(),
                'access_token_id' => $accessTokenId = $refreshTokenEntity->getAccessToken()->getIdentifier(),
                'family_id' => $familyId,
                'resources' => $issuance?->resources,
                'dpop_jkt' => $binding,
                'revoked' => false,
                'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
            ]);

            $this->saveToken($token);

            $token->getConnection()->afterCommitOrNow(function () use ($id, $accessTokenId): void {
                if ($this->events->hasListeners(RefreshTokenCreated::class)) {
                    $this->events->dispatch(new RefreshTokenCreated($id, $accessTokenId));
                }
            });
        };

        if ($issuance !== null) {
            $persist();
        } else {
            // Direct repository calls still need to save the family and token atomically.
            $token->getConnection()->transaction($persist);
        }
    }

    /**
     * Revoke a refresh token.
     *
     * {@inheritdoc}
     */
    public function revokeRefreshToken(string $tokenId): void
    {
        $issuance = TokenIssuance::current();

        if ($issuance?->grant instanceof RefreshTokenModel && $issuance->grant->getKey() === $tokenId) {
            $issuance->claimGrant();

            return;
        }

        Passport::refreshToken()->newQuery()->whereKey($tokenId)->update(['revoked' => true]);
    }

    /**
     * Determine whether a refresh token has been revoked.
     *
     * {@inheritdoc}
     */
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $token = Passport::refreshToken();
        TokenIssuance::ensureRedemptionAllowed($token->getConnection());

        $record = $token->newQuery()->useWritePdo()->find($tokenId);
        $issuance = TokenIssuance::current();
        $issuance?->setGrant($record);

        if ($record === null) {
            return true;
        }

        // Prove ownership before replay detection can revoke the family. Bindings belong
        // to individual refresh tokens so an upgrade cannot disable theft detection for an older token.
        if ($record->dpop_jkt !== null && $record->dpop_jkt !== $issuance?->dpopJkt) {
            throw OAuthServerException::invalidGrant();
        }

        if (! Passport::$revokeRefreshTokenAfterUse && $issuance?->client !== null
            && ! $issuance->client->isConfidential() && $record->dpop_jkt === null) {
            return true;
        }

        if ($record->revoked) {
            TokenIssuance::revokeRefreshTokenFamily($record->family_id);

            return true;
        }

        return false;
    }
}
