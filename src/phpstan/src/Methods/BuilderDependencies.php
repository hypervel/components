<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Query\Builder as QueryBuilder;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;

/**
 * Declare the custom builder inputs that methods forwarded to an Eloquent builder are described from.
 *
 * A caller of a forwarded method depends only on the class it calls through and the classes its types
 * name, so a model or relation caller never depends on the model's custom builder or query class. The
 * inputs are therefore declared on the owner every caller depends on: the model for model and relation
 * calls, the builder for direct calls.
 *
 * @internal
 */
#[AutowiredService]
class BuilderDependencies
{
    /** @var array<string, array{list<string>, ?string}> */
    private array $inputs = [];

    /**
     * Create a builder dependency tracker.
     */
    public function __construct(
        private readonly DeclarationDependencyTracker $declarationDependencyTracker,
        private readonly BuilderHelper $builderHelper,
    ) {
    }

    /**
     * Declare that the owner's forwarded methods depend on the builder's custom declarations and passthru methods.
     */
    public function track(ClassReflection $owner, ClassReflection $builder): void
    {
        // Keyed by instantiation: the query class can come from the builder's active template types.
        [$classNames, $passthruFile] = $this->inputs[$builder->getCacheKey()] ??= $this->resolveInputs($builder);

        foreach ($classNames as $className) {
            if ($className !== $owner->getName()) {
                $this->declarationDependencyTracker->trackClassDependency($owner, $className);
            }
        }

        if ($passthruFile !== null) {
            $this->declarationDependencyTracker->trackFileDependency($owner, $passthruFile);
        }
    }

    /**
     * Resolve the custom builder and query classes, and the file declaring a custom passthru list.
     *
     * @return array{list<string>, ?string}
     */
    private function resolveInputs(ClassReflection $builder): array
    {
        $classNames = [];

        if ($builder->getName() !== EloquentBuilder::class) {
            $classNames[] = $builder->getName();
        }

        // Ordinary calls run on the stored query and passthru calls on toBase()'s.
        foreach ($this->builderHelper->queryTypes($builder) as $queryType) {
            foreach ($queryType->getObjectClassNames() as $queryClassName) {
                if ($queryClassName !== QueryBuilder::class && ! in_array($queryClassName, $classNames, true)) {
                    $classNames[] = $queryClassName;
                }
            }
        }

        // A property default is not part of a class's declarations.
        $property = $builder->getNativeProperty('passthru');
        $source = $property->getDeclaringTrait() ?? $property->getDeclaringClass();
        $passthruFile = $source->getName() === EloquentBuilder::class ? null : $source->getFileName();

        return [$classNames, $passthruFile];
    }
}
