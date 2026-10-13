<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Hypervel\Passport\Concerns\PersistsTokens;
use Hypervel\Passport\DeviceCode as DeviceCodeModel;
use Hypervel\Passport\Passport;
use Hypervel\Support\Facades\Date;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;

class DeviceCodeRepository implements DeviceCodeRepositoryInterface
{
    use PersistsTokens;

    /**
     * Create a new device code entity.
     *
     * {@inheritdoc}
     */
    public function getNewDeviceCode(): DeviceCodeEntityInterface
    {
        return new DeviceCode;
    }

    /**
     * Persist a device code, approval or poll timestamp.
     *
     * {@inheritdoc}
     */
    public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void
    {
        if (! is_null($deviceCodeEntity->getUserIdentifier())) {
            $issuance = TokenIssuance::current();
            $issuance?->begin();

            $updated = Passport::deviceCode()->newQuery()
                ->whereKey($deviceCodeEntity->getIdentifier())
                ->whereNull('user_id')
                ->where('revoked', false)
                ->where('expires_at', '>', Date::now())
                ->update([
                    ...($issuance?->attributes() ?? []),
                    'user_id' => $deviceCodeEntity->getUserIdentifier(),
                    'user_approved_at' => $deviceCodeEntity->getUserApproved() ? Date::now() : null,
                ]);

            if ($updated === 0) {
                throw OAuthServerException::invalidRequest('user_code');
            }
        } elseif (! is_null($deviceCodeEntity->getLastPolledAt())) {
            Passport::deviceCode()->newQuery()->whereKey($deviceCodeEntity->getIdentifier())->update([
                'last_polled_at' => $deviceCodeEntity->getLastPolledAt(),
            ]);
        } else {
            TokenIssuance::current()?->begin();

            $code = Passport::deviceCode()->forceFill([
                'id' => $deviceCodeEntity->getIdentifier(),
                'user_id' => null,
                'client_id' => $deviceCodeEntity->getClient()->getIdentifier(),
                'user_code' => $deviceCodeEntity->getUserCode(),
                'scopes' => $deviceCodeEntity->getScopes(),
                'revoked' => false,
                'user_approved_at' => null,
                'last_polled_at' => null,
                'expires_at' => $deviceCodeEntity->getExpiryDateTime(),
            ]);

            $this->persistToken($code, [$code->getKeyName(), 'user_code']);
        }
    }

    /**
     * Retrieve a device code entity by its identifier.
     *
     * {@inheritdoc}
     */
    public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface
    {
        $code = Passport::deviceCode();
        $issuance = TokenIssuance::current();

        if ($issuance?->isTokenRequest()) {
            TokenIssuance::ensureRedemptionAllowed($code->getConnection());
        }

        $record = $code->newQuery()->useWritePdo()->whereKey($deviceCode)->where('revoked', false)->first();
        $issuance?->setGrant($record);

        return $record ? $this->fromDeviceCodeModel($record) : null;
    }

    /**
     * Get the device code entity by the given user code.
     */
    public function getDeviceCodeEntityByUserCode(string $userCode): ?DeviceCodeEntityInterface
    {
        $record = Passport::deviceCode()->newQuery()
            ->useWritePdo()
            ->where('user_code', $userCode)
            ->whereNull('user_id')
            ->where('expires_at', '>', Date::now())
            ->where('revoked', false)
            ->first();

        return $record ? $this->fromDeviceCodeModel($record) : null;
    }

    /**
     * Revoke a device code.
     *
     * {@inheritdoc}
     */
    public function revokeDeviceCode(string $codeId): void
    {
        $code = Passport::deviceCode();

        if (! TokenIssuance::current()?->hasClaimed($code, $codeId)) {
            $code->newQuery()->whereKey($codeId)->update(['revoked' => true]);
        }
    }

    /**
     * Determine whether a device code has been revoked.
     *
     * {@inheritdoc}
     */
    public function isDeviceCodeRevoked(string $codeId): bool
    {
        $record = TokenIssuance::current()?->grant;

        if ($record instanceof DeviceCodeModel && $record->getKey() === $codeId) {
            return $record->revoked;
        }

        return Passport::deviceCode()->newQuery()->useWritePdo()->whereKey($codeId)->where('revoked', false)->doesntExist();
    }

    /**
     * Create a new device code entity from the given device code model instance.
     */
    protected function fromDeviceCodeModel(DeviceCodeModel $model): DeviceCodeEntityInterface
    {
        return new DeviceCode(
            $model->getKey(),
            $model->user_id === null ? null : (string) $model->user_id,
            $model->client_id,
            $model->scopes,
            ! is_null($model->user_approved_at),
            $model->last_polled_at?->toDateTimeImmutable(),
            $model->expires_at?->toDateTimeImmutable()
        );
    }
}
