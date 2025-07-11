<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Exceptions;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Support\Arr;

class MissingAbilityException extends AuthorizationException
{
    /**
     * The abilities that the user did not have.
     *
     * @var array<string>
     */
    protected array $abilities;

    /**
     * Create a new missing scope exception.
     *
     * @param array<string>|string $abilities
     */
    public function __construct(array|string $abilities = [], string $message = 'Invalid ability provided.')
    {
        parent::__construct($message);

        $this->abilities = Arr::wrap($abilities);
    }

    /**
     * Get the abilities that the user did not have.
     *
     * @return array<string>
     */
    public function abilities(): array
    {
        return $this->abilities;
    }
}