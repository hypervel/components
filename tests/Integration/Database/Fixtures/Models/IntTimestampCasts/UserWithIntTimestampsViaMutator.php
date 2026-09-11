<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\CarbonImmutable;

class UserWithIntTimestampsViaMutator extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['email'];

    /**
     * Get the updated timestamp as a date.
     */
    protected function getUpdatedAtAttribute(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    /**
     * Set the updated timestamp for storage.
     */
    protected function setUpdatedAtAttribute(mixed $value): void
    {
        $this->attributes['updated_at'] = CarbonImmutable::parse($value)->timestamp;
    }

    /**
     * Get the created timestamp as a date.
     */
    protected function getCreatedAtAttribute(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    /**
     * Set the created timestamp for storage.
     */
    protected function setCreatedAtAttribute(mixed $value): void
    {
        $this->attributes['created_at'] = CarbonImmutable::parse($value)->timestamp;
    }
}
