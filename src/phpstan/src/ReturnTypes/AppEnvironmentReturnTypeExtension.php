<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\BooleanType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

class AppEnvironmentReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create an extension for the given application class.
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
     * Determine whether the method gets or checks the environment.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'environment';
    }

    /**
     * Get the environment name without arguments, or the result of checking it with them.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        return self::getEnvironmentType($methodCall->getArgs(), $scope, $methodReflection->getVariants()[0]->getReturnType());
    }

    /**
     * Get the type of an environment() call with the given arguments.
     *
     * Hypervel: upstream counts the arguments, but an unpacked empty array passes none. A narrower
     * declaration than the inferred type is kept.
     *
     * @param list<Arg> $args
     */
    public static function getEnvironmentType(array $args, Scope $scope, Type $declaredType): ?Type
    {
        $type = new StringType;

        foreach ($args as $arg) {
            $passesValues = $arg->unpack ? $scope->getType($arg->value)->isIterableAtLeastOnce() : TrinaryLogic::createYes();

            if ($passesValues->yes()) {
                $type = new BooleanType;

                break;
            }

            if ($passesValues->maybe()) {
                // A later argument can still prove that values are passed.
                $type = null;
            }
        }

        return $type !== null && $declaredType->isSuperTypeOf($type)->yes() ? $type : null;
    }
}
