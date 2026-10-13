<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Support\Collection;

/**
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<PersonalAccessToken, TDeclaringModel>
 */
class PersonalAccessTokenRelation extends MorphMany
{
    /**
     * The maximum number of tokens deleted and invalidated per batch.
     */
    protected const int DELETE_BATCH_SIZE = 1000;

    /**
     * Delete the related personal access tokens.
     */
    public function delete(): mixed
    {
        if (! config()->boolean('sanctum.cache.enabled', false)) {
            return $this->getQuery()->delete();
        }

        $key = $this->getRelated()->getKeyName();
        $qualifiedKey = $this->getRelated()->getQualifiedKeyName();
        $tokenModel = $this->getRelated()::class;

        // Batching must preserve the atomicity of the uncached, single-statement delete.
        return $this->getRelated()->getConnection()->transaction(function () use ($key, $qualifiedKey, $tokenModel): int {
            $deleted = 0;

            (clone $this->getQuery())->select($qualifiedKey)->useWritePdo()->toBase()->chunkById(
                self::DELETE_BATCH_SIZE,
                function (Collection $rows) use ($key, $tokenModel, &$deleted): void {
                    $ids = $rows->pluck($key)->all();
                    $deleted += (clone $this->getQuery())->whereKey($ids)->delete();

                    foreach ($ids as $id) {
                        $tokenModel::clearTokenCache($id);
                    }
                },
                $qualifiedKey,
                $key,
            );

            return $deleted;
        });
    }
}
