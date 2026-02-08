<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ReflectionClass;

/**
 * Provides access to protected and private members of an object.
 */
class ClassInvoker
{
    protected ReflectionClass $reflection;

    public function __construct(
        protected object $instance
    ) {
        $this->reflection = new ReflectionClass($instance);
    }

    /**
     * Get a property value from the wrapped instance.
     */
    public function __get(string $name): mixed
    {
        $property = $this->reflection->getProperty($name);

        return $property->getValue($this->instance);
    }

    /**
     * Call a method on the wrapped instance.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $method = $this->reflection->getMethod($name);

        return $method->invokeArgs($this->instance, $arguments);
    }
}
