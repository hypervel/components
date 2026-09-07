<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Contracts\Container\Container;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Mappers\NameMapper;
use Hypervel\Data\Mappers\ProvidedNameMapper;

class NameMapperResolver
{
    /**
     * Create a new name mapper resolver.
     */
    public function __construct(
        protected readonly Container $container,
    ) {
    }

    /**
     * Resolve the input mapper declared by the attributes.
     */
    public function resolveInput(
        DataAttributesCollection $attributes,
        ?NameMapper $default = null,
    ): ?NameMapper {
        $attribute = $attributes->first(MapInputName::class)
            ?? $attributes->first(MapName::class);

        if ($attribute === null) {
            return $default;
        }

        $mapper = $attribute->newInstance();

        return $this->resolve($mapper->input);
    }

    /**
     * Resolve the output mapper declared by the attributes.
     */
    public function resolveOutput(
        DataAttributesCollection $attributes,
        ?NameMapper $default = null,
    ): ?NameMapper {
        $attribute = $attributes->first(MapOutputName::class)
            ?? $attributes->first(MapName::class);

        if ($attribute === null) {
            return $default;
        }

        $mapper = $attribute->newInstance();

        return $this->resolve($mapper->output);
    }

    /**
     * Resolve a configured mapper class.
     *
     * @param null|class-string<NameMapper> $mapper
     */
    public function resolveConfigured(?string $mapper): ?NameMapper
    {
        return $mapper === null ? null : $this->resolve($mapper);
    }

    /**
     * Resolve one mapper declaration.
     */
    protected function resolve(string|int|NameMapper $mapper): NameMapper
    {
        if ($mapper instanceof NameMapper) {
            return $mapper;
        }

        if (is_string($mapper) && is_a($mapper, NameMapper::class, true)) {
            /** @var NameMapper $resolved */
            $resolved = $this->container->make($mapper);

            return $resolved;
        }

        return new ProvidedNameMapper($mapper);
    }
}
