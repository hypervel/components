<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class Url implements ParameterizedRule
{
    use FormatsParameters;

    /**
     * @var null|string[]
     */
    protected ?array $protocols = null;

    /**
     * Specify the allowed URL protocols.
     *
     * @param string[] $protocols
     */
    public function protocols(array $protocols): static
    {
        $this->protocols = $protocols;

        return $this;
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['url', ...array_values($this->protocols ?? [])];
    }
}
