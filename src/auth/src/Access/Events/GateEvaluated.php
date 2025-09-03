<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access\Events;

use Hypervel\Auth\Access\Response;
use Hypervel\Auth\Contracts\Authenticatable;

class GateEvaluated
{
    /**
     * Create a new event instance.
     *
     * @param null|Authenticatable $user the authenticatable model
     * @param string $ability the ability being evaluated
     * @param null|bool|Response $result the result of the evaluation
     * @param array $arguments the arguments given during evaluation
     */
    public function __construct(
        public ?Authenticatable $user,
        public string $ability,
        public bool|Response|null $result,
        public array $arguments
    ) {
    }
}
