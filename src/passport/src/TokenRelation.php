<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

/**
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<Token, TDeclaringModel>
 */
class TokenRelation extends HasMany
{
    /**
     * Update the related access tokens.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return app(PassportCache::class)->update($this->getQuery(), $values);
    }

    /**
     * Delete the related access tokens.
     */
    public function delete(): mixed
    {
        return app(PassportCache::class)->delete($this->getQuery());
    }
}
