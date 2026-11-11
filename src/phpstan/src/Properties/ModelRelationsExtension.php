<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\Support\Str;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;

use function array_key_exists;
use function str_ends_with;
use function substr;

/** @internal */
#[AutowiredService]
class ModelRelationsExtension implements PropertiesClassReflectionExtension
{
    /**
     * Create a new model relations extension instance.
     */
    public function __construct(private CollectionHelper $collectionHelper)
    {
    }

    /** @var array<string, bool> */
    private array $hasPropertyCache = [];

    /**
     * Determine whether the property is a relation or a relation count.
     */
    public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (! $classReflection->is(Model::class)) {
            return false;
        }

        $cacheKey = $classReflection->getCacheKey() . '-' . $propertyName;

        if (array_key_exists($cacheKey, $this->hasPropertyCache)) {
            return $this->hasPropertyCache[$cacheKey];
        }

        return $this->hasPropertyCache[$cacheKey] = $this->resolveHasProperty($classReflection, $propertyName);
    }

    /**
     * Determine whether the property is a relation or a relation count, without the memoization.
     */
    private function resolveHasProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        if (ReflectionHelper::hasPropertyTag($classReflection, $propertyName)) {
            return false;
        }

        // Unlike upstream, a relation method named like a count is a relation, as the model reads it.
        if ($this->isRelationMethod($classReflection, $propertyName)) {
            return true;
        }

        if (! str_ends_with($propertyName, '_count')) {
            return false;
        }

        // Unlike upstream's Str::before(), only the suffix is removed, so stock_count_items_count names stock_count_items().
        $relationName = substr($propertyName, 0, -6);

        return $this->isRelationMethod($classReflection, Str::camel($relationName))
            || $this->isRelationMethod($classReflection, $relationName);
    }

    /**
     * Determine whether the model declares a method of the name that returns a relation.
     */
    private function isRelationMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->hasNativeMethod($methodName)
            && (new ObjectType(Relation::class))->isSuperTypeOf($classReflection->getNativeMethod($methodName)->getVariants()[0]->getReturnType())->yes();
    }

    /**
     * Get the relation's result or count property.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        if (str_ends_with($propertyName, '_count') && ! $this->isRelationMethod($classReflection, $propertyName)) {
            return new ModelProperty($classReflection, IntegerRangeType::createAllGreaterThanOrEqualTo(0), new NeverType, false);
        }

        $returnType = $classReflection->getMethod($propertyName, new OutOfClassScope)
            ->getVariants()[0]
            ->getReturnType();

        $relationType = TypeTraverser::map($returnType, static function (Type $type, callable $traverse): Type {
            // A relation intersected with an interface yields its result, which the interface does not describe.
            if ((new ObjectType(Relation::class))->isSuperTypeOf($type)->yes()) {
                return $type->getTemplateType(Relation::class, 'TResult');
            }

            if ($type instanceof UnionType || $type instanceof IntersectionType) {
                return $traverse($type);
            }

            return $type;
        });

        $relationType = $this->collectionHelper->replaceCollectionsInType($relationType);

        return new ModelProperty($classReflection, $relationType, new NeverType, false);
    }
}
