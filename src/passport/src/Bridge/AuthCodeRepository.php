<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Passport\Concerns\PersistsTokens;
use Hypervel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    use PersistsTokens;

    /**
     * Create a new authorization code entity.
     *
     * {@inheritdoc}
     */
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode;
    }

    /**
     * Persist a new authorization code.
     *
     * {@inheritdoc}
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $issuance = TokenIssuance::current();
        $issuance?->begin();

        $code = Passport::authCode()->forceFill([
            ...($issuance?->attributes() ?? []),
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => $authCodeEntity->getScopes(),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ]);

        $this->persistToken($code);
    }

    /**
     * Revoke an authorization code.
     *
     * {@inheritdoc}
     */
    public function revokeAuthCode(string $codeId): void
    {
        $code = Passport::authCode();

        if (! TokenIssuance::current()?->hasClaimed($code, $codeId)) {
            $code->newQuery()->whereKey($codeId)->update(['revoked' => true]);
        }
    }

    /**
     * Determine whether an authorization code has been revoked.
     *
     * {@inheritdoc}
     */
    public function isAuthCodeRevoked(string $codeId): bool
    {
        $code = Passport::authCode();
        TokenIssuance::ensureRedemptionAllowed($code->getConnection());

        $record = $code->newQuery()->useWritePdo()->find($codeId);
        TokenIssuance::current()?->setGrant($record);

        return $record === null || $record->revoked;
    }
}
