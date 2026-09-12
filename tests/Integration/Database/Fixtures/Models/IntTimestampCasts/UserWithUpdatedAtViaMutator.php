<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts;

use Hypervel\Database\Eloquent\Model;

class UserWithUpdatedAtViaMutator extends Model
{
    protected ?string $table = 'users_nullable_timestamps';

    protected array $fillable = ['email', 'updated_at'];

    /**
     * Set the updated timestamp only for an existing model.
     */
    public function setUpdatedAtAttribute(mixed $value): void
    {
        if (! $this->id) {
            return;
        }

        $this->attributes['updated_at'] = $value;
    }
}
