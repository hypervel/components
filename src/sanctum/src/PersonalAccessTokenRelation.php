<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
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

        $tokenModel = $this->getRelated()::class;

        return Container::getInstance()->make(ModelCacheCoordinator::class)->mutate(
            $this->getQuery(),
            static fn (Builder $batch): int => $batch->delete(),
            static function (array $ids) use ($tokenModel): void {
                foreach ($ids as $id) {
                    $tokenModel::clearTokenCache($id);
                }
            },
        );
    }
}
