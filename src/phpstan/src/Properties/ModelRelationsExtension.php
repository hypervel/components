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
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use ReflectionException;

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
    public function __construct(
        private CollectionHelper $collectionHelper,
        private ReflectionProvider $reflectionProvider,
        private ModelHelper $modelHelper,
        private ClosureTypeFactory $closureTypeFactory,
    ) {
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

        // Unlike upstream, a relation named like a count is a relation, as the model reads it.
        if ($this->relationReturnType($classReflection, $propertyName) !== null) {
            return true;
        }

        if (! str_ends_with($propertyName, '_count')) {
            return false;
        }

        // Unlike upstream's Str::before(), only the suffix is removed, so stock_count_items_count names stock_count_items().
        $relationName = substr($propertyName, 0, -6);

        return $this->relationReturnType($classReflection, Str::camel($relationName)) !== null
            || $this->relationReturnType($classReflection, $relationName) !== null;
    }

    /**
     * Get the declared return of the relation the model loads by the name, or null when the name is no relation.
     *
     * A relation method declares it. Unlike upstream, so does the resolver of a relation that
     * resolveRelationUsing() registers, unless a native method takes the name.
     */
    private function relationReturnType(ClassReflection $classReflection, string $name): ?Type
    {
        if (! $this->isRelationMethod($classReflection, $name)->no()) {
            return $classReflection->getMethod($name, new OutOfClassScope)->getVariants()[0]->getReturnType();
        }

        if ($classReflection->hasNativeMethod($name)) {
            return null;
        }

        try {
            $resolver = $this->modelHelper->getRelationResolver($classReflection, $name);
        } catch (ReflectionException) {
            return null;
        }

        // The resolver is never run.
        return $resolver === null ? null : $this->closureTypeFactory->fromClosureObject($resolver)->getReturnType();
    }

    /**
     * Determine whether a model method is a relation the model can load: a native method that the base model
     * does not declare, callable without arguments, returning a relation.
     *
     * Unlike upstream, a method without a declared relation return may be a relation of unknown type.
     */
    public function isRelationMethod(ClassReflection $classReflection, string $methodName): TrinaryLogic
    {
        // Model::getAttribute() never treats the base model's own methods as relations.
        if (! $classReflection->hasNativeMethod($methodName)
            || $this->reflectionProvider->getClass(Model::class)->hasNativeMethod($methodName)) {
            return TrinaryLogic::createNo();
        }

        $variant = $classReflection->getNativeMethod($methodName)->getVariants()[0];

        foreach ($variant->getParameters() as $parameter) {
            if (! $parameter->isOptional()) {
                return TrinaryLogic::createNo();
            }
        }

        return (new ObjectType(Relation::class))->isSuperTypeOf($variant->getReturnType())->result;
    }

    /**
     * Get the relation's result or count property.
     */
    public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
    {
        $returnType = $this->relationReturnType($classReflection, $propertyName);

        if ($returnType === null) {
            return new ModelProperty($classReflection, IntegerRangeType::createAllGreaterThanOrEqualTo(0), new NeverType, false);
        }

        // A relation whose declared return may not be a relation, such as an untyped one, loads an unknown result.
        if (! (new ObjectType(Relation::class))->isSuperTypeOf($returnType)->yes()) {
            return new ModelProperty($classReflection, new MixedType, new NeverType, false);
        }

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
