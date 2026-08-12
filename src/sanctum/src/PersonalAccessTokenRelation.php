<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;

/**
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<PersonalAccessToken, TDeclaringModel>
 */
class PersonalAccessTokenRelation extends MorphMany
{
    /**
     * Delete the related personal access tokens.
     */
    public function delete(): mixed
    {
        if (! config('sanctum.cache.enabled')) {
            return $this->getQuery()->delete();
        }

        /** @var array<int, int|string> $ids */
        $ids = (clone $this->getQuery())
            ->pluck($this->getRelated()->getQualifiedKeyName())
            ->all();

        if ($ids === []) {
            return 0;
        }

        $deleted = (clone $this->getQuery())->whereKey($ids)->delete();

        $this->settleInvalidation($ids);

        return $deleted;
    }

    /**
     * Clear the selected tokens after their database transaction settles.
     *
     * @param array<int, int|string> $ids
     */
    protected function settleInvalidation(array $ids): void
    {
        $related = $this->getRelated();
        $connection = $related->getConnection();
        $tokenModel = $related::class;
        $callback = static function () use ($ids, $tokenModel): void {
            foreach ($ids as $id) {
                $tokenModel::clearTokenCache($id);
            }
        };

        if ($connection->getTransactionManager() === null && $connection->transactionLevel() === 0) {
            $callback();

            return;
        }

        $connection->afterCommit($callback);
    }
}
