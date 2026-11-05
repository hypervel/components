<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function str_contains;

/**
 * Hypervel: registered for FormRequest and the Validator contract, whose safe() is the same operation.
 */
class FormRequestSafeDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create an extension for the given class.
     *
     * @param class-string $class
     */
    public function __construct(private string $class)
    {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Determine whether the method returns validated input.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'safe';
    }

    /**
     * Get the shape of the validated input for the given keys.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $args = $methodCall->getArgs();

        if (count($args) === 0 || $args[0]->unpack) {
            return null;
        }

        $argType = $scope->getType($args[0]->value);

        if (! $argType->isConstantArray()->yes()) {
            return null;
        }

        $types = [];

        foreach ($argType->getConstantArrays() as $constantArray) {
            $builder = ConstantArrayTypeBuilder::createEmpty();

            foreach ($constantArray->getValueTypes() as $keyType) {
                $keys = $keyType->getConstantStrings();

                // Hypervel: a non-constant key can name any input, so the shape is unknown.
                if ($keys === []) {
                    return null;
                }

                foreach ($keys as $constantString) {
                    // Hypervel: only() nests dotted keys and expands wildcards.
                    if (str_contains($constantString->getValue(), '.') || str_contains($constantString->getValue(), '*')) {
                        return null;
                    }

                    // Hypervel: optional, because only() omits a key the validated input does not contain.
                    $builder->setOffsetValueType($constantString, new MixedType, true);
                }
            }

            $types[] = $builder->getArray();
        }

        return TypeCombinator::union(...$types);
    }
}
