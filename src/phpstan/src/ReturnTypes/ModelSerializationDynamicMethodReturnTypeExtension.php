<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use DateTimeInterface;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEncryptedCollection;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Properties\ModelCastHelper;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;

use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function lcfirst;
use function str_contains;

/**
 * Types the arrays a model serializes to from its database attributes, appends and visibility.
 */
#[AutowiredService]
class ModelSerializationDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new model serialization return type extension instance.
     */
    public function __construct(private ModelPropertyHelper $properties, private ModelCastHelper $casts)
    {
    }

    /**
     * Get the class whose methods the extension types.
     */
    public function getClass(): string
    {
        return Model::class;
    }

    /**
     * Determine whether the extension types the method.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['toArray', 'attributesToArray'], true);
    }

    /**
     * Get the array type a model serializes to, or null to keep the declared type.
     *
     * Unlike upstream, a model whose database attributes are not all known keeps the declared type.
     */
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $types = [];

        foreach ($scope->getType($methodCall->var)->getObjectClassReflections() as $class) {
            if (! $class->is(Model::class)) {
                continue;
            }

            if ($class->isAbstract() || $class->getName() === Model::class) {
                return null;
            }

            // Defer to serializer and configuration overrides without executing application methods.
            foreach (array_unique([$methodReflection->getName(), 'attributesToArray', 'getAppends', 'getHidden', 'getVisible']) as $method) {
                if ($class->getNativeMethod($method)->getDeclaringClass()->getName() !== Model::class) {
                    return null;
                }
            }

            $databaseNames = $this->properties->getDatabasePropertyNames($class);

            if ($databaseNames === null) {
                return null;
            }

            $appends = $this->configuredNames($class, 'Appends');
            $hidden = $this->configuredNames($class, 'Hidden');
            $visible = $this->configuredNames($class, 'Visible');
            $names = array_unique(array_merge($databaseNames, $appends));

            if ($names === []) {
                return null;
            }

            $array = ConstantArrayTypeBuilder::createEmpty();

            foreach ($names as $name) {
                if (in_array($name, $hidden, true) || ($visible !== [] && ! in_array($name, $visible, true))) {
                    continue;
                }

                $array->setOffsetValueType(new ConstantStringType($name), $this->attributeType($class, $name, in_array($name, $appends, true), $scope), true);
            }

            // Partial selects omit columns; aggregates, aliases and loaded relations add keys.
            $array->makeUnsealed(new StringType, new MixedType);
            $types[] = $array->getArray();
        }

        return $types === [] ? null : TypeCombinator::union(...$types);
    }

    /**
     * Get the names a model configures through its property and the class attribute that Model::resolveClassAttribute() finds.
     *
     * @return array<string>
     */
    private function configuredNames(ClassReflection $class, string $attribute): array
    {
        $defaults = $class->getNativeReflection()->getProperty(lcfirst($attribute))->getDefaultValue();
        $defaults = is_array($defaults) ? array_filter($defaults, is_string(...)) : [];

        do {
            foreach ([$class, ...$class->getTraits()] as $source) {
                foreach ($source->getNativeReflection()->getAttributes() as $config) {
                    if ($config->getName() !== 'Hypervel\Database\Eloquent\Attributes\\' . $attribute) {
                        continue;
                    }

                    $arguments = array_values($config->getArguments());
                    $columns = is_array($arguments[0] ?? null) ? $arguments[0] : $arguments;

                    return array_unique(array_merge($defaults, array_filter($columns, is_string(...))));
                }
            }

            $class = $class->getParentClass();
        } while ($class !== null);

        return $defaults;
    }

    /**
     * Get the type an attribute serializes to, following mutateAttributeForArray() and the cast and date handling.
     */
    private function attributeType(ClassReflection $class, string $name, bool $appended, Scope $scope): Type
    {
        $cast = $this->casts->getCastForProperty($class, $name);

        // A cast that cannot be determined may be a class cast, which mutateAttributeForArray() applies even before an accessor.
        if ($cast === null && $this->casts->hasCastForProperty($class, $name)) {
            return new MixedType;
        }

        $castReflection = $cast === null ? null : $this->casts->getCastClass($cast);
        // Unlike property reads, serialization prefers an Attribute getter to a get{Name}Attribute() method.
        $attributeGetter = $this->properties->getAttributeGetMutatorType($class, $name);
        $getter = $attributeGetter ?? $this->properties->getGetMutatorType($class, $name);
        $enumCast = false;

        // mutateAttributeForArray() serializes an attribute with an accessor, and every appended one.
        if ($getter !== null || $appended) {
            if ($castReflection !== null && ! $castReflection->isEnum()) {
                // It reads a class cast before any accessor, without its serialize hook. An inbound caster leaves the raw value.
                $type = $this->properties->getDatabaseProperty($class, $name)->getReadableType();
                $date = false;
            } elseif ($getter !== null) {
                $type = $getter;
                // Only the Attribute getter branch serializes the dates it returns.
                $date = $attributeGetter !== null;
            } else {
                // mutateAttribute() calls a get{Name}Attribute() method the model does not have.
                return new MixedType;
            }

            $cast = null;
        } elseif ($this->properties->hasDatabaseProperty($class, $name)->yes()) {
            $type = $this->properties->getDatabaseProperty($class, $name)->getReadableType();
            $date = true;
            $enumCast = $castReflection?->isEnum() ?? false;

            if ($castReflection !== null && ! $enumCast && ! $castReflection->is(CastsInboundAttributes::class)) {
                if (in_array($castReflection->getName(), [AsArrayObject::class, AsEncryptedArrayObject::class], true)) {
                    return TypeCombinator::addNull(new ArrayType(TypeCombinator::union(new IntegerType, new StringType), new MixedType));
                }

                if ($castReflection->is(Castable::class) && ! in_array($castReflection->getName(), [AsCollection::class, AsEncryptedCollection::class, AsStringable::class], true)) {
                    // A castUsing factory can choose a serializer dynamically.
                    return new MixedType;
                }

                if ($castReflection->hasNativeMethod('serialize')) {
                    $serialized = $castReflection->getNativeMethod('serialize')->getVariants()[0]->getReturnType();
                    // addCastAttributesToArray() serializes only a value that is set, so null stays null.
                    $type = $type->isNull()->no() ? $serialized : TypeCombinator::addNull($serialized);
                    $date = false;
                }
            }
        } else {
            return new MixedType;
        }

        return TypeTraverser::map($type, static function (Type $type, callable $traverse) use ($class, $scope, $cast, $date, $enumCast): Type {
            if ($type instanceof UnionType) {
                return $traverse($type);
            }

            if ($date && (new ObjectType(DateTimeInterface::class))->isSuperTypeOf($type)->yes()) {
                if ($cast !== null && str_contains($cast, ':') && in_array(explode(':', $cast)[0], ['date', 'datetime', 'immutable_date', 'immutable_datetime'], true)) {
                    return new StringType;
                }

                return $class->getNativeMethod('serializeDate')->getVariants()[0]->getReturnType();
            }

            // A collection that overrides toArray() serializes as the override declares.
            if ((new ObjectType(Enumerable::class))->isSuperTypeOf($type)->yes()
                && in_array($type->getMethod('toArray', $scope)->getDeclaringClass()->getName(), [Collection::class, LazyCollection::class], true)) {
                $value = TypeTraverser::map($type->getIterableValueType(), static function (Type $item, callable $traverse) use ($scope): Type {
                    if ($item instanceof UnionType) {
                        return $traverse($item);
                    }

                    return (new ObjectType(Arrayable::class))->isSuperTypeOf($item)->yes()
                        ? $item->getMethod('toArray', $scope)->getVariants()[0]->getReturnType()
                        : $item;
                });

                return new ArrayType($type->getIterableKeyType(), $value);
            }

            if ((new ObjectType(Arrayable::class))->isSuperTypeOf($type)->yes()) {
                return $type->getMethod('toArray', $scope)->getVariants()[0]->getReturnType();
            }

            if ($enumCast && $type->isEnum()->yes()) {
                $values = [];

                foreach ($type->getEnumCases() as $case) {
                    $values[] = $case->getBackingValueType() ?? new ConstantStringType($case->getEnumCaseName());
                }

                return $values === [] ? new MixedType : TypeCombinator::union(...$values);
            }

            return $type;
        });
    }
}
