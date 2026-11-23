<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Closure;
use Error;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionException;

use function method_exists;

/** @internal */
#[AutowiredService]
class ModelHelper
{
    /** @var array<string, Model> */
    private array $instances = [];

    /**
     * Create a new model helper instance.
     */
    public function __construct(
        private DeclarationDependencyTracker $declarationDependencyTracker,
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the model's instance, created without running its constructor and memoized per class, so callers must
     * treat it as read-only.
     *
     * What the instance reads, such as its connection, table, key and casts, comes from property defaults and
     * method bodies that are not part of the model's declarations, so their files become dependencies of the
     * files depending on the model.
     *
     * @throws ReflectionException
     */
    public function getInstance(ClassReflection $classReflection): Model
    {
        $className = $classReflection->getName();

        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        $model = self::newInstanceWithoutConstructor($classReflection);

        foreach (SourceFiles::forInstance($this->reflectionProvider, $classReflection, Model::class) as $file) {
            $this->declarationDependencyTracker->trackFileDependency($classReflection, $file);
        }

        return $this->instances[$className] = $model;
    }

    /**
     * Get the resolver that resolveRelationUsing() registered at boot for the model's relation, without running it.
     *
     * @throws ReflectionException when the model cannot be instantiated, so its relations are unknown
     */
    public function getRelationResolver(ClassReflection $classReflection, string $name): ?Closure
    {
        $this->runtimeInputs->track($classReflection);

        return $this->getInstance($classReflection)->relationResolver($classReflection->getName(), $name);
    }

    /**
     * Create a model instance without running its constructor.
     *
     * @throws ReflectionException
     */
    public static function newInstanceWithoutConstructor(ClassReflection $classReflection): Model
    {
        try {
            /** @var Model $model */
            $model = $classReflection->getNativeReflection()->newInstanceWithoutConstructor();
        } catch (Error $error) {
            throw new ReflectionException($error->getMessage(), 0, $error);
        }

        // The trait initializers run before the class attributes, as in the constructor. Unique
        // string ids decide the key type and incrementing, so they must not depend on call order.
        $model->initializeHasTimestamps();

        if (method_exists($model, 'initializeHasUniqueStringIds')) {
            $model->initializeHasUniqueStringIds();
        }

        $model->initializeModelAttributes();

        return $model;
    }
}
