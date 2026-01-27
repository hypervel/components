<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\stubs;

use Hypervel\Database\Eloquent\Contracts\CastsAttributes;
use Hypervel\Database\Eloquent\Model;

class TestCast implements CastsAttributes
{
    /**
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return TestValueObject|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        if (! json_validate($value)) {
            return null;
        }
        $value = json_decode($value, true);
        if (! is_array($value)) {
            return null;
        }

        return TestValueObject::make($value);
    }

    /**
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array
     */
    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        if (! $value instanceof TestValueObject) {
            return [
                $key => null,
            ];
        }

        return [
            $key => json_encode($value->toArray()),
        ];
    }
}
