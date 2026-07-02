<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Actions;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Support\Collection;

class PruneOrphanedPasskeys
{
    /**
     * Prune passkeys whose polymorphic owner no longer exists.
     *
     * @param null|callable(string): void $warn
     * @return array<string, int>
     */
    public function __invoke(bool $dryRun = false, int $chunkSize = 1000, ?callable $warn = null): array
    {
        $counts = [];
        $passkeyModel = Passkeys::passkeyModel();

        $passkeyModel::query()
            ->select('user_type')
            ->distinct()
            ->orderBy('user_type')
            ->pluck('user_type')
            ->each(function (mixed $userType) use ($passkeyModel, $dryRun, $chunkSize, $warn, &$counts): void {
                if (! is_string($userType) || $userType === '') {
                    return;
                }

                $counts[$userType] = $this->pruneType($passkeyModel, $userType, $dryRun, $chunkSize, $warn);
            });

        return array_filter(
            $counts,
            static fn (int $count): bool => $count > 0,
        );
    }

    /**
     * Prune orphaned passkeys for one owner type.
     *
     * @param class-string<Passkey> $passkeyModel
     * @param null|callable(string): void $warn
     */
    private function pruneType(string $passkeyModel, string $userType, bool $dryRun, int $chunkSize, ?callable $warn): int
    {
        $ownerClass = Relation::getMorphedModel($userType);

        if ($ownerClass === null) {
            if (! str_contains($userType, '\\')) {
                if ($warn !== null) {
                    $warn("Skipping passkeys for unresolved morph alias [{$userType}]. Register the morph map before pruning.");
                }

                return 0;
            }

            if (! class_exists($userType)) {
                return $this->deleteOwnerType($passkeyModel, $userType, $dryRun);
            }

            $ownerClass = $userType;
        }

        if (! is_subclass_of($ownerClass, Model::class)) {
            if ($warn !== null) {
                $warn("Skipping passkeys for owner type [{$userType}] because it does not resolve to an Eloquent model.");
            }

            return 0;
        }

        $deleted = 0;

        $passkeyModel::query()
            ->where('user_type', $userType)
            ->chunkById($chunkSize, function (Collection $passkeys) use ($passkeyModel, $ownerClass, $dryRun, &$deleted): void {
                $orphanedPasskeyIds = $this->orphanedPasskeyIds($passkeys, $ownerClass);
                $deleted += count($orphanedPasskeyIds);

                if ($orphanedPasskeyIds !== [] && ! $dryRun) {
                    $passkeyModel::query()
                        ->whereKey($orphanedPasskeyIds)
                        ->delete();
                }
            });

        return $deleted;
    }

    /**
     * Delete every passkey for an owner type that no longer exists.
     *
     * @param class-string<Passkey> $passkeyModel
     */
    private function deleteOwnerType(string $passkeyModel, string $userType, bool $dryRun): int
    {
        $query = $passkeyModel::query()->where('user_type', $userType);
        $count = $query->count();

        if (! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    /**
     * Get the passkey IDs whose owners are missing.
     *
     * @param Collection<int, Passkey> $passkeys
     * @param class-string<Model> $ownerClass
     *
     * @return array<int, mixed>
     */
    private function orphanedPasskeyIds(Collection $passkeys, string $ownerClass): array
    {
        /** @var Model $owner */
        $owner = new $ownerClass;
        $ownerKeyName = $owner->getKeyName();

        $ownerIds = $passkeys
            ->pluck('user_id')
            ->filter(static fn (mixed $identifier): bool => is_scalar($identifier))
            ->map(static fn (mixed $identifier): string => (string) $identifier)
            ->unique()
            ->values()
            ->all();

        if ($ownerIds === []) {
            return [];
        }

        $existingOwnerIds = $owner->newQueryWithoutScopes()
            ->whereIn($ownerKeyName, $ownerIds)
            ->pluck($ownerKeyName)
            ->map(static fn (mixed $identifier): string => (string) $identifier)
            ->all();

        $existingOwnerIds = array_flip($existingOwnerIds);

        return $passkeys
            ->filter(static fn (Passkey $passkey): bool => ! is_scalar($passkey->user_id)
                || ! isset($existingOwnerIds[(string) $passkey->user_id]))
            ->map(static fn (Passkey $passkey): mixed => $passkey->getKey())
            ->values()
            ->all();
    }
}
