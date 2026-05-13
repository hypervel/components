<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Contracts;

interface CastInputs
{
    /**
     * Transform the inputs from the underlying input value.
     *
     * @param string $key The inputs key
     * @param mixed $value The inputs value
     * @param array $inputs All inputs
     * @return mixed The transformed value
     */
    public function get(string $key, mixed $value, array $inputs): mixed;
}
