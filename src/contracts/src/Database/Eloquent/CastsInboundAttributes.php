<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database\Eloquent;

use Hypervel\Database\Eloquent\Model;

interface CastsInboundAttributes
{
    /**
     * Transform the attribute to its underlying model values.
     *
     * @param array<string, mixed> $attributes
     * @return mixed
     */
    public function set(Model $model, string $key, mixed $value, array $attributes);
}
