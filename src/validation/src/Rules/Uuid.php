<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class Uuid implements ParameterizedRule
{
    use FormatsParameters;

    /**
     * @var null|'max'|'nil'|int<0, 8>
     */
    protected int|string|null $version = null;

    /**
     * Specify the UUID version.
     *
     * @param 'max'|'nil'|int<0, 8> $version
     */
    public function version(int|string $version): static
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return $this->version === null ? ['uuid'] : ['uuid', $this->version];
    }
}
