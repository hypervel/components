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
        $query = $this->getQuery();

        // Batching must preserve the atomicity of the uncached, single-statement delete.
        return $this->getRelated()->getConnection()->transaction(function () use ($query, $key, $qualifiedKey, $tokenModel): int {
            $deleted = 0;

            $selection = (clone $query)->select($qualifiedKey)->useWritePdo()->toBase();
            $deleteBatch = function (array $ids) use ($query, $tokenModel, &$deleted): void {
                $deleted += (clone $query)->whereKey($ids)->limit(null)->offset(null)->delete();

                foreach ($ids as $id) {
                    $tokenModel::clearTokenCache($id);
                }
            };

            if ($selection->getLimit() !== null) {
                foreach (array_chunk($selection->pluck($qualifiedKey)->all(), self::DELETE_BATCH_SIZE) as $ids) {
                    $deleteBatch($ids);
                }
            } else {
                if ($offset = $selection->getOffset()) {
                    $excluded = (clone $selection)->offset(0)->limit($offset)->pluck($qualifiedKey)->all();
                    $selection->whereNotIn($qualifiedKey, $excluded)->offset(0);
                }

                // Other sort columns would make chunkById skip records between batches.
                $selection->reorder()->chunkById(
                    self::DELETE_BATCH_SIZE,
                    static fn (Collection $rows) => $deleteBatch($rows->pluck($key)->all()),
                    $qualifiedKey,
                    $key,
                );
            }

            return $deleted;
        });
    }
}
