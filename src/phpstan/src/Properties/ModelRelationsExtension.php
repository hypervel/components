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
final class ModelRelationsExtension implements PropertiesClassReflectionExtension
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

        if (str_ends_with($propertyName, '_count')) {
            // Unlike upstream's Str::before(), only the suffix is removed, so stock_count_items_count names stock_count_items().
            $propertyName = substr($propertyName, 0, -6);

            $methodNames = [Str::camel($propertyName), $propertyName];
        } else {
            $methodNames = [$propertyName];
        }

        foreach ($methodNames as $methodName) {
            if (! $classReflection->hasNativeMethod($methodName)) {
                continue;
            }

            $returnType = $classReflection->getNativeMethod($methodName)->getVariants()[0]->getReturnType();

            if ((new ObjectType(Relation::class))->isSuperTypeOf($returnType)->yes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the relation's result or count property.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        if (str_ends_with($propertyName, '_count')) {
            return new ModelProperty($classReflection, IntegerRangeType::createAllGreaterThanOrEqualTo(0), new NeverType, false);
        }

        $returnType = $classReflection->getMethod($propertyName, new OutOfClassScope)
            ->getVariants()[0]
            ->getReturnType();

        $relationType = TypeTraverser::map($returnType, static function (Type $type, callable $traverse): Type {
            if ($type instanceof UnionType || $type instanceof IntersectionType) {
                return $traverse($type);
            }

            if (! (new ObjectType(Relation::class))->isSuperTypeOf($type)->yes()) {
                return $type;
            }

            return $type->getTemplateType(Relation::class, 'TResult');
        });

        $relationType = $this->collectionHelper->replaceCollectionsInType($relationType);

        return new ModelProperty($classReflection, $relationType, new NeverType, false);
    }
}
