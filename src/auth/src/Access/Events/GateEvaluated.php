<?php

declare(strict_types=1);

namespace Hypervel\Auth\Access\Events;

use Hypervel\Auth\Access\Response;

class GateEvaluated
{
    /**
     * Create a new event instance.
     *
     * @param mixed $user the user being evaluated
     * @param string $ability the ability being evaluated
     * @param null|bool|Response $result the result of the evaluation
     * @param array $arguments the arguments given during evaluation
     */
    public function __construct(
        public mixed $user,
        public string $ability,
        public bool|Response|null $result,
        public array $arguments,
    ) {
    }
}
