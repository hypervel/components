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
        if (! config()->boolean('sanctum.cache.enabled', false)) {
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
        $tokenModel = $this->getRelated()::class;

        foreach ($ids as $id) {
            $tokenModel::clearTokenCache($id);
        }

        return $deleted;
    }
}
