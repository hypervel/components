<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectShapeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use stdClass;

use function array_map;
use function count;
use function in_array;
use function is_string;

#[AutowiredService]
class LiteralExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Determine whether the function is literal().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'literal';
    }

    /**
     * Get the type of the single positional argument, or of the object built from the arguments.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        $args = $functionCall->getArgs();

        // Handle the case of a single argument, returning its type directly
        if (count($args) === 1) {
            $argType = $scope->getType($args[0]->value);

            $nameOfParam = $args[0]->getAttributes()['originalArg']->name->name ?? null;

            if ($nameOfParam === null) {
                // Upstream also returns a non-constant unpacked array itself, which literal()
                // never returns, an object for a one-item list, which it returns unwrapped,
                // and keeps integer keys that PHP passes by position.
                if ($args[0]->unpack) {
                    $constantArrays = $argType->getConstantArrays();

                    return $constantArrays === []
                        ? new MixedType
                        : TypeCombinator::union(...array_map($this->getTypeFromConstantArray(...), $constantArrays));
                }

                if ($argType->isObject()->yes() && in_array(stdClass::class, $argType->getReferencedClasses(), true)) {
                    return TypeCombinator::intersect(new ObjectShapeType([], []), $argType);
                }

                return $argType;
            }
        }

        $properties = [];

        foreach ($args as $index => $argExpression) {
            // The names an unpacked argument passes are not known here.
            if ($argExpression->unpack) {
                return new MixedType;
            }

            $nameOfParam = $argExpression->getAttributes()['originalArg']->name->name ?? null;

            if ($nameOfParam === null) {
                // Handle unnamed arguments
                $nameOfParam = $index;
            }

            $properties[(string) $nameOfParam] = $scope->getType($argExpression->value);
        }

        return TypeCombinator::intersect(
            new ObjectShapeType($properties, []),
            new ObjectType(stdClass::class),
        );
    }

    /**
     * Get the type literal() returns for the given unpacked arguments.
     */
    protected function getTypeFromConstantArray(ConstantArrayType $argType): Type
    {
        // An unsealed array may pass any number of other arguments.
        if ($argType->isUnsealed()->yes()) {
            return new MixedType;
        }

        $valueTypes = $argType->getValueTypes();
        $positionalTypes = [];
        $requiredPositionalTypes = [];
        $positionalProperties = [];
        $namedProperties = [];
        $optionalProperties = [];
        $optionalPosition = null;
        $passesName = false;

        foreach ($argType->getKeyTypes() as $i => $keyType) {
            $key = $keyType->getValue();
            $isOptional = $argType->isOptionalKey($i);

            if (is_string($key)) {
                $namedProperties[$key] = $valueTypes[$i];

                if ($isOptional) {
                    $optionalProperties[] = $key;
                } else {
                    $passesName = true;
                }

                continue;
            }

            // PHP passes integer keys by position, and throws when one follows a named argument,
            // so an optional one is never passed then.
            if ($passesName) {
                if ($isOptional) {
                    continue;
                }

                return new NeverType(true);
            }

            // An optional argument moves the ones after it, so only earlier positions are known.
            if ($optionalPosition === null) {
                $position = count($positionalTypes);
                $positionalProperties[$position] = $valueTypes[$i];

                if ($isOptional) {
                    $optionalPosition = $position;
                }
            }

            $positionalTypes[] = $valueTypes[$i];

            if (! $isOptional) {
                $requiredPositionalTypes[] = $valueTypes[$i];
            }
        }

        // The optional argument's own position is known only when no positional argument follows it.
        if ($optionalPosition !== null) {
            if ($optionalPosition === count($positionalTypes) - 1) {
                $optionalProperties[] = $optionalPosition;
            } else {
                unset($positionalProperties[$optionalPosition]);
            }
        }

        $types = [];

        // A single positional argument is returned as it is.
        if (! $passesName && $positionalTypes !== [] && count($requiredPositionalTypes) <= 1) {
            $types[] = $requiredPositionalTypes[0] ?? TypeCombinator::union(...$positionalTypes);
        }

        if (count($positionalTypes) !== 1 || $requiredPositionalTypes === [] || $namedProperties !== []) {
            $types[] = TypeCombinator::intersect(
                new ObjectShapeType($positionalProperties + $namedProperties, $optionalProperties),
                new ObjectType(stdClass::class),
            );
        }

        return TypeCombinator::union(...$types);
    }
}
