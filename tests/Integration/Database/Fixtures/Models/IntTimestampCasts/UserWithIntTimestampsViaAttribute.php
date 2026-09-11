<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts;

use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\CarbonImmutable;

class UserWithIntTimestampsViaAttribute extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['email'];

    /**
     * Get the updated timestamp attribute.
     */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value),
            set: fn (mixed $value): int => CarbonImmutable::parse($value)->timestamp,
        );
    }

    /**
     * Get the created timestamp attribute.
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value),
            set: fn (mixed $value): int => CarbonImmutable::parse($value)->timestamp,
        );
    }
}
