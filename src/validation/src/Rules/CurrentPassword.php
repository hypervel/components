<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class CurrentPassword implements ParameterizedRule
{
    use FormatsParameters;

    protected ?string $guard = null;

    /**
     * Specify the authentication guard.
     */
    public function guard(string $guard): static
    {
        $this->guard = $guard;

        return $this;
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return $this->guard === null ? ['current_password'] : ['current_password', $this->guard];
    }
}
