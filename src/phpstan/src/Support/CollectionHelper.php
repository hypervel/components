<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\HasCollection;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;

use function array_map;
use function count;
use function in_array;
use function ltrim;

#[AutowiredService]
final class CollectionHelper
{
    /**
     * The framework collections, whose own method implementations the extensions and rules rely on.
     */
    private const array FRAMEWORK_COLLECTIONS = [Collection::class, LazyCollection::class, EloquentCollection::class];

    /**
     * Create a new collection helper instance.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Determine whether the class runs a framework collection's own implementation of each
     * method, which a subclass may override.
     *
     * @param list<string> $methods
     */
    public static function usesFrameworkMethods(ClassReflection $classReflection, array $methods): bool
    {
        foreach ($methods as $method) {
            if (! $classReflection->hasNativeMethod($method)
                || ! in_array($classReflection->getNativeMethod($method)->getDeclaringClass()->getName(), self::FRAMEWORK_COLLECTIONS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the collection type that collect() makes from a scalar, null or an empty array.
     *
     * collect()'s declared signature already describes objects and other arrays. Upstream
     * also typed objects here, casting Arrayable objects with (array) instead of reading
     * toArray(), and forcing integer keys onto Eloquent collections.
     */
    public function determineGenericCollectionTypeFromType(Type $type): ?GenericObjectType
    {
        if (! $type->isObject()->no()) {
            return null;
        }

        // Matches Arr::wrap(), which collect() uses for scalars and null.
        $arrayType = $type->toArray();

        if ($arrayType->isIterableAtLeastOnce()->no()) {
            return new GenericObjectType(Collection::class, [new BenevolentUnionType([new IntegerType, new StringType]), new MixedType]);
        }

        if ($type->isArray()->yes()) {
            return null;
        }

        return new GenericObjectType(Collection::class, [$arrayType->getIterableKeyType(), $arrayType->getIterableValueType()]);
    }

    /**
     * Get the collection type declared by the model's `CollectedBy` attribute or by the
     * return type of its `newCollection()` method, which can be a union or an intersection.
     */
    private function determineCollectionType(string $modelClassName): Type
    {
        try {
            $modelReflection = $this->reflectionProvider->getClass($modelClassName);
            $newCollectionMethod = $modelReflection->getNativeMethod('newCollection');

            // The attribute is read by HasCollection::newCollection(), so it has no effect when that method is overridden.
            $newCollectionFileName = $newCollectionMethod->getDeclaringClass()->getNativeReflection()->getMethod('newCollection')->getFileName();

            if ($newCollectionFileName === $this->reflectionProvider->getClass(HasCollection::class)->getFileName()) {
                $collectionClassName = $this->findCollectedByClassName($modelReflection);

                if ($collectionClassName !== null) {
                    return new ObjectType($collectionClassName);
                }
            }

            return $newCollectionMethod->getVariants()[0]->getReturnType();
        } catch (MissingMethodFromReflectionException|ShouldNotHappenException) {
            return new ObjectType(EloquentCollection::class);
        }
    }

    /**
     * Find the collection class of the nearest `CollectedBy` attribute, as
     * HasCollection::resolveCollectionFromAttribute() does.
     */
    private function findCollectedByClassName(ClassReflection $modelReflection): ?string
    {
        foreach ([$modelReflection, ...$modelReflection->getParents()] as $classReflection) {
            $attributes = $classReflection->getNativeReflection()->getAttributes(CollectedBy::class);

            if ($attributes === []) {
                continue;
            }

            // The attribute is instantiated, so its argument may be named.
            $arguments = $attributes[0]->getArgumentsExpressions();
            $expr = $arguments['collectionClass'] ?? $arguments[0] ?? null;

            if ($expr instanceof ClassConstFetch && $expr->class instanceof Name) {
                return $expr->class->toString();
            }

            if ($expr instanceof String_) {
                return ltrim($expr->value, '\\');
            }

            return null;
        }

        return null;
    }

    /**
     * Get the collection type of the model, with the model and key types filled in.
     */
    public function determineCollectionClass(string $modelClassName, ?Type $modelType = null, ?Type $keyType = null): Type
    {
        $modelType ??= new ObjectType($modelClassName);

        return TypeTraverser::map($this->determineCollectionType($modelClassName), function (Type $type, callable $traverse) use ($modelType, $keyType): Type {
            if ($type instanceof UnionType || $type instanceof IntersectionType) {
                return $traverse($type);
            }

            // Members that are not collections keep their declared type arguments.
            if (! (new ObjectType(Enumerable::class))->isSuperTypeOf($type)->yes()) {
                return TypeTraverser::map($type, static fn (Type $type, callable $traverse): Type => $type instanceof StaticType ? $modelType : $traverse($type));
            }

            $classNames = $type->getObjectClassNames();

            if (count($classNames) !== 1) {
                return $type;
            }

            $collectionClassName = $classNames[0];
            $collectionReflection = $this->reflectionProvider->getClass($collectionClassName);

            if ($collectionReflection->isGeneric()) {
                $typeMap = $collectionReflection->getActiveTemplateTypeMap();

                // Specifies key and value
                if ($typeMap->count() === 2) {
                    return new GenericObjectType($collectionClassName, [$keyType ?? new IntegerType, $modelType]);
                }

                // Specifies only value
                if (($typeMap->count() === 1) && $typeMap->hasType('TModel')) {
                    return new GenericObjectType($collectionClassName, [$modelType]);
                }
            }

            // Not generic. So return the type as is
            return new ObjectType($collectionClassName);
        });
    }

    /**
     * Replace each Eloquent collection in the type with its model's collection type.
     */
    public function replaceCollectionsInType(Type $type): Type
    {
        if (! in_array(EloquentCollection::class, $type->getReferencedClasses(), true)) {
            return $type;
        }

        return TypeTraverser::map($type, function ($type, $traverse): Type {
            if ($type instanceof UnionType || $type instanceof IntersectionType) {
                return $traverse($type);
            }

            if (! (new ObjectType(EloquentCollection::class))->isSuperTypeOf($type)->yes()) {
                return $traverse($type);
            }

            $models = $type->getTemplateType(EloquentCollection::class, 'TModel')->getObjectClassNames();

            if (count($models) === 0) {
                return $type;
            }

            return TypeCombinator::union(...array_map([$this, 'determineCollectionClass'], $models));
        });
    }
}
