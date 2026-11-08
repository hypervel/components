<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\ModelProperty;

use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use PHPStan\Type\AcceptsResult;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeReference;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

use function count;
use function explode;
use function sprintf;
use function str_contains;

/**
 * A string naming a database property of a model, written model-property<TModel>.
 */
class GenericModelPropertyType extends StringType
{
    /**
     * Create a new generic model property type instance.
     */
    public function __construct(private Type $type, private ModelPropertyHelper $modelPropertyHelper)
    {
        parent::__construct();
    }

    /**
     * Describe the type.
     */
    public function describe(VerbosityLevel $level): string
    {
        return 'model property of ' . $this->type->describe($level);
    }

    /**
     * Get the classes the type refers to.
     *
     * @return string[]
     */
    public function getReferencedClasses(): array
    {
        return $this->getGenericType()->getReferencedClasses();
    }

    /**
     * Get the model type whose properties the string names.
     */
    public function getGenericType(): Type
    {
        return $this->type;
    }

    /**
     * Determine whether the type equals another.
     */
    public function equals(Type $type): bool
    {
        return $type instanceof self && $this->type->equals($type->type);
    }

    /**
     * Determine whether the type accepts another, checking a constant string against the model's properties.
     *
     * A "table.column" string is checked against that table on the model's connection.
     */
    public function accepts(Type $type, bool $strictTypes): AcceptsResult
    {
        if ($type instanceof CompoundType) {
            return $type->isAcceptedBy($this, $strictTypes);
        }

        if (count($type->getConstantStrings()) === 1) {
            $givenString = $type->getConstantStrings()[0]->getValue();
            $genericType = $this->getGenericType();

            if ($genericType instanceof MixedType) {
                return AcceptsResult::createYes();
            }

            if (count($genericType->getObjectClassNames()) < 1) {
                return AcceptsResult::createYes();
            }

            if (str_contains($givenString, ' as ')) {
                $givenString = explode(' as ', $givenString)[0];
            }

            if (str_contains($givenString, '.')) {
                $parts = explode('.', $givenString);

                if (count($parts) !== 2) {
                    return AcceptsResult::createYes();
                }

                [$tableName, $propertyName] = $parts;

                foreach ($genericType->getObjectClassReflections() as $classReflection) {
                    if ($this->modelPropertyHelper->hasTableColumn($classReflection, $tableName, $propertyName)) {
                        return AcceptsResult::createYes();
                    }
                }

                return AcceptsResult::createNo([sprintf('Database table "%s" does not have column "%s"', $tableName, $propertyName)]);
            }

            $reasons = [];

            if (! $genericType->hasInstanceProperty($givenString)->yes()) {
                $reasons[] = sprintf('The given string should be a property of %s, %s given.', $this->type->describe(VerbosityLevel::value()), $givenString);
            }

            return new AcceptsResult($genericType->hasInstanceProperty($givenString), $reasons);
        }

        if ($type instanceof self) {
            return new AcceptsResult($this->getGenericType()->accepts($type->getGenericType(), $strictTypes)->result, [sprintf('The given string should be a property of %s', $this->type->describe(VerbosityLevel::value()))]);
        }

        if ($type->isString()->yes()) {
            // It is an arbitrary string, so we cannot check if it is a property of the model.
            // We return yes to not cause issues on levels 7+
            return AcceptsResult::createYes();
        }

        return AcceptsResult::createNo();
    }

    /**
     * Determine whether the type is a supertype of another.
     */
    public function isSuperTypeOf(Type $type): IsSuperTypeOfResult
    {
        $constantStrings = $type->getConstantStrings();

        if (count($constantStrings) === 1) {
            if (! $this->getGenericType()->hasInstanceProperty($constantStrings[0]->getValue())->yes()) {
                return IsSuperTypeOfResult::createNo();
            }

            return IsSuperTypeOfResult::createYes();
        }

        if ($type instanceof self) {
            return $this->getGenericType()->isSuperTypeOf($type->getGenericType());
        }

        if ($type instanceof CompoundType) {
            return $type->isSubTypeOf($this);
        }

        return IsSuperTypeOfResult::createNo();
    }

    /**
     * Map the model type through a callback.
     */
    public function traverse(callable $cb): Type
    {
        $newType = $cb($this->getGenericType());

        if ($newType === $this->getGenericType()) {
            return $this;
        }

        return new self($newType, $this->modelPropertyHelper);
    }

    /**
     * Infer the model template type from a received type.
     */
    public function inferTemplateTypes(Type $receivedType): TemplateTypeMap
    {
        // @phpstan-ignore phpstanApi.instanceofType (Each member infers on its own, as in PHPStan's generic types.)
        if ($receivedType instanceof UnionType || $receivedType instanceof IntersectionType) {
            return $receivedType->inferTemplateTypesOn($this);
        }

        $constantStrings = $receivedType->getConstantStrings();

        if (count($constantStrings) === 1) {
            $typeToInfer = new ObjectType($constantStrings[0]->getValue());
        } elseif ($receivedType instanceof self) {
            $typeToInfer = $receivedType->type;
        } elseif ($receivedType->isClassString()->yes()) {
            $typeToInfer = $this->getGenericType();

            if ($typeToInfer instanceof TemplateType) {
                $typeToInfer = $typeToInfer->getBound();
            }

            $typeToInfer = TypeCombinator::intersect($typeToInfer, new ObjectWithoutClassType);
        } else {
            return TemplateTypeMap::createEmpty();
        }

        if (! $this->getGenericType()->isSuperTypeOf($typeToInfer)->no()) {
            return $this->getGenericType()->inferTemplateTypes($typeToInfer);
        }

        return TemplateTypeMap::createEmpty();
    }

    /**
     * Get the template types the model type refers to.
     *
     * @return TemplateTypeReference[]
     */
    public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
    {
        $variance = $positionVariance->compose(TemplateTypeVariance::createCovariant());

        return $this->getGenericType()->getReferencedTemplateTypes($variance);
    }

    /**
     * Determine whether the type has template or late-resolvable parts.
     */
    public function hasTemplateOrLateResolvableType(): bool
    {
        return true;
    }

    /**
     * Restore the type from var_export() output.
     *
     * @param mixed[] $properties
     */
    public static function __set_state(array $properties): Type
    {
        return new self($properties['type'], $properties['modelPropertyHelper']);
    }
}
