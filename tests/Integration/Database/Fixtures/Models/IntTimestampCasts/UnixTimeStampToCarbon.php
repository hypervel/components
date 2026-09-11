<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\CarbonImmutable;

class UnixTimeStampToCarbon implements CastsAttributes
{
    /**
     * Transform the stored timestamp into a date.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    /**
     * Transform the date into a timestamp for storage.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return CarbonImmutable::parse($value)->timestamp;
    }
}
