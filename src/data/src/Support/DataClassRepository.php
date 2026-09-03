<?php

declare(strict_types=1);

namespace Hypervel\Data\Support;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotFindDataClass;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Support\ClassMetadataCache;

class DataClassRepository
{
    /** @var array<class-string<BaseData>, DataClass> */
    protected array $classes = [];

    /** @var array<class-string<BaseData>, bool> */
    protected array $dynamicRuleGraphs = [];

    /**
     * Create a new data class repository.
     */
    public function __construct(
        protected readonly DataClassFactory $factory,
    ) {
    }

    /**
     * Get immutable metadata for a data class.
     *
     * @template TData of BaseData
     *
     * @param class-string<TData> $class
     */
    public function get(string $class): DataClass
    {
        if (! is_a($class, BaseData::class, true)) {
            throw CannotFindDataClass::forClass($class);
        }

        return $this->classes[$class] ??= $this->factory->build(
            ClassMetadataCache::reflectClass($class),
        );
    }

    /**
     * Determine if a validated class graph can produce payload-dependent rules.
     *
     * @param class-string<BaseData> $class
     */
    public function hasDynamicRuleGraph(string $class): bool
    {
        if (array_key_exists($class, $this->dynamicRuleGraphs)) {
            return $this->dynamicRuleGraphs[$class];
        }

        $visited = [];

        if ($this->resolveDynamicRuleGraph($class, $visited)) {
            return true;
        }

        foreach (array_keys($visited) as $visitedClass) {
            $this->dynamicRuleGraphs[$visitedClass] = false;
        }

        return false;
    }

    /**
     * Traverse one dynamic-rule graph without caching incomplete cycle results.
     *
     * @param class-string<BaseData> $class
     * @param array<class-string<BaseData>, true> $visited
     */
    protected function resolveDynamicRuleGraph(string $class, array &$visited): bool
    {
        if (array_key_exists($class, $this->dynamicRuleGraphs)) {
            return $this->dynamicRuleGraphs[$class];
        }

        if (isset($visited[$class])) {
            return false;
        }

        $visited[$class] = true;
        $dataClass = $this->get($class);

        if ($dataClass->propertyMorphable || $dataClass->hasLifecycleMethod('rules')) {
            return $this->dynamicRuleGraphs[$class] = true;
        }

        $contextualProperties = [];

        foreach ($dataClass->constructorParameters as $parameter) {
            if ($parameter->isPromoted && $parameter->contextualAttribute !== null) {
                $contextualProperties[$parameter->name] = true;
            }
        }

        foreach ($dataClass->properties as $property) {
            if ($property->computed
                || ! $property->validate
                || isset($contextualProperties[$property->name])
            ) {
                continue;
            }

            $nestedClasses = [];
            $dataObjectTypes = $property->type->getDataObjectTypes();
            $dataCollectableTypes = $property->type->getDataCollectableTypes();

            if (count($dataObjectTypes) === 1 && $dataObjectTypes[0]->dataClass !== null) {
                $nestedClasses[$dataObjectTypes[0]->dataClass] = true;
            }

            if (count($dataCollectableTypes) === 1 && $dataCollectableTypes[0]->dataClass !== null) {
                $nestedClasses[$dataCollectableTypes[0]->dataClass] = true;
            }

            foreach (array_keys($nestedClasses) as $nestedClass) {
                if ($this->resolveDynamicRuleGraph($nestedClass, $visited)) {
                    return $this->dynamicRuleGraphs[$class] = true;
                }
            }
        }

        return false;
    }
}
