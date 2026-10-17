<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DateInterval;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Support\Facades\Date;
use RuntimeException;

class ConsentRepository
{
    /**
     * Find the approval for a client and its approving identity.
     */
    public function findForUser(string $clientId, ?string $provider, int|string $userId): ?Consent
    {
        return Passport::consent()->newQuery()->useWritePdo()->where([
            'client_id' => $clientId,
            'provider' => $provider ?? '',
            'user_id' => $userId,
        ])->first();
    }

    /**
     * Begin issuance and lock an approval, optionally creating a new authorization.
     */
    public function lockForUser(string $clientId, ?string $provider, int|string $userId, bool $create = true): ?Consent
    {
        $identity = ['client_id' => $clientId, 'provider' => $provider ?? '', 'user_id' => $userId];
        // Probe before beginning: an InnoDB read inside the transaction retains duplicate-key locks after savepoint rollback.
        // A locking probe of a missing identity also takes a gap lock that prevents concurrent creation.
        $exists = $create && $this->findForUser($clientId, $provider, $userId) !== null;
        TokenIssuance::current()?->begin();
        $query = Passport::consent()->newQuery()->lockForUpdate();

        if (! $create) {
            return $query->where($identity)->first();
        }

        $consent = $exists ? (clone $query)->where($identity)->first() : null;
        $consent ??= $query->createOrFirst($identity, ['scopes' => [], 'permissions' => [], 'remembered_until' => Date::now()]);

        if (! $consent->exists) {
            throw new RuntimeException('Consent persistence was canceled.');
        }

        return $consent;
    }

    /**
     * Remember the selected permissions while holding the consent's row lock.
     *
     * @param array{scopes: list<string>, permissions: array<string, array>} $presented
     * @param array{scopes: list<string>, permissions: array<string, array>} $approved
     */
    public function remember(Consent $consent, array $presented, array $approved, ?DateInterval $duration): void
    {
        $remembered = $consent->isRemembered();
        $scopes = $remembered ? $consent->scopes : [];
        $permissions = $remembered ? $consent->permissions : [];

        if (empty($presented['permissions']['resources'])) {
            $scopes = $this->mergePermissions($scopes, $presented['scopes'], $approved['scopes']);
        }

        foreach ($presented['permissions'] as $namespace => $values) {
            if ($namespace === 'resources') {
                foreach ($values as $resource => $resourceScopes) {
                    $permissions['resources'][$resource] = $this->mergePermissions(
                        $permissions['resources'][$resource] ?? [],
                        $resourceScopes,
                        $approved['permissions']['resources'][$resource] ?? [],
                    );
                }
            } else {
                $permissions[$namespace] = $this->mergePermissions(
                    $permissions[$namespace] ?? [],
                    $values,
                    $approved['permissions'][$namespace] ?? [],
                );
            }
        }

        if (! $consent->forceFill([
            'scopes' => $scopes,
            'permissions' => $permissions,
            'remembered_until' => $duration === null ? null : Date::now()->add($duration),
        ])->save()) {
            throw new RuntimeException('Consent persistence was canceled.');
        }
    }

    /**
     * Replace presented choices without discarding unrelated approvals.
     *
     * @param list<string> $previous
     * @param list<string> $presented
     * @param list<string> $approved
     * @return list<string>
     */
    protected function mergePermissions(array $previous, array $presented, array $approved): array
    {
        return array_values(array_unique([...array_diff($previous, array_diff($presented, $approved)), ...$approved]));
    }

    /**
     * Revoke credentials while the caller holds the consent's transaction and row lock.
     */
    public function revokeCredentials(Consent $consent): void
    {
        // Exchanges lock their grant or family first; wait for them before selecting their products.
        Passport::authCode()->newQuery()->where('consent_id', $consent->getKey())
            ->where('revoked', false)->update(['revoked' => true]);

        // Disabling a grant does not discard its credentials; they must stay revoked if it is enabled again.
        Passport::deviceCode()->newQuery()->where('consent_id', $consent->getKey())
            ->where('revoked', false)->update(['revoked' => true]);

        Passport::refreshTokenFamily()->newQuery()->where('consent_id', $consent->getKey())
            ->whereNull('revoked_at')->update(['revoked_at' => Date::now()]);
        Passport::refreshToken()->newQuery()->where('consent_id', $consent->getKey())
            ->where('revoked', false)->update(['revoked' => true]);

        TokenIssuance::revokeAccessTokens(Passport::token()->newQuery()->where('consent_id', $consent->getKey()));
    }
}
