<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\stubs;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;

class TestCast implements CastsAttributes
{
    /**
     * @return null|TestValueObject
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
