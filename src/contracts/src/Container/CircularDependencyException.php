<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Container;

use Exception;
use Psr\Container\ContainerExceptionInterface;

class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    /**
     * The dependency chain that forms the cycle.
     *
     * @var string[]
     */
    protected array $dependencyChain = [];

    /**
     * Whether the chain has been sealed (cycle is complete).
     */
    protected bool $sealed = false;

    /**
     * Add a definition name to the dependency chain.
     *
     * As the exception bubbles up through resolve() calls, each level prepends
     * its abstract name. The chain is sealed once the same name appears twice,
     * indicating the full cycle has been captured (e.g., A -> B -> C -> A).
     */
    public function addDefinitionName(string $name): void
    {
        if ($this->sealed) {
            return;
        }

        if (in_array($name, $this->dependencyChain, true)) {
            $this->sealed = true;
        }

        array_unshift($this->dependencyChain, $name);

        $this->message = 'Circular dependency detected: ' . implode(' -> ', $this->dependencyChain);
    }

    /**
     * Get the dependency chain that forms the cycle.
     *
     * @return string[]
     */
    public function getDependencyChain(): array
    {
        return $this->dependencyChain;
    }
}
