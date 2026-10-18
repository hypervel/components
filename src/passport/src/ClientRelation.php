<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;

/**
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<Client, TDeclaringModel>
 */
class ClientRelation extends MorphMany
{
    /**
     * Update the related OAuth clients.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return app(PassportCache::class)->update($this->getQuery(), $values);
    }

    /**
     * Delete the related OAuth clients.
     */
    public function delete(): mixed
    {
        return app(PassportCache::class)->delete($this->getQuery());
    }
}
