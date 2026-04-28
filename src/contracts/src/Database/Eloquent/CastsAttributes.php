<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database\Eloquent;

use Hypervel\Database\Eloquent\Model;

/**
 * @template TGet
 * @template TSet
 */
interface CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     *
     * @param array<string, mixed> $attributes
     * @return null|TGet
     */
    public function get(Model $model, string $key, mixed $value, array $attributes);

    /**
     * Transform the attribute to its underlying model values.
     *
     * @param null|TSet $value
     * @param array<string, mixed> $attributes
     * @return mixed
     */
    public function set(Model $model, string $key, mixed $value, array $attributes);
}
