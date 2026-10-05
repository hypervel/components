<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\References;

use Hypervel\Container\Container;

class ContainerReference implements ExternalReference
{
    /**
     * Create a container reference.
     *
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly string $dependency,
        public readonly ?string $property = null,
        public readonly array $parameters = [],
    ) {
    }

    /**
     * Resolve the dependency, or the given property of it, from the container.
     */
    public function getValue(): mixed
    {
        // An unresolvable binding is a configuration error, so it fails instead of becoming a null rule parameter.
        $dependency = Container::getInstance()->make($this->dependency, $this->parameters);

        if ($this->property !== null) {
            return data_get($dependency, $this->property);
        }

        return $dependency;
    }
}
