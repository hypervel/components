<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Types\Factory\ModelFactoryType;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\TrinaryLogic;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function in_array;

#[AutowiredService]
class FactoryDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Factory::class;
    }

    /**
     * Determine whether the method is supported.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return true;
    }

    /**
     * Get the return type of a factory method, following whether the factory makes one model or several.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $calledOnType = $scope->getType($methodCall->var);

        if (! $calledOnType instanceof ModelFactoryType) {
            return null;
        }

        $methodName = $methodReflection->getName();

        // Unlike upstream, a factory's own implementation of these methods is not treated as the base one.
        $isBaseMethod = $methodReflection->getDeclaringClass()->getName() === Factory::class;

        if ($isBaseMethod && in_array($methodName, ['count', 'times'], true)) {
            // Unlike upstream's error type, a missing count keeps the declaration beside PHPStan's own report.
            if ($methodCall->getArgs() === []) {
                return null;
            }

            $argType = $scope->getType($methodCall->getArgs()[0]->value);

            if ($argType->isInteger()->yes()) {
                return $calledOnType->withSingleModel(TrinaryLogic::createNo());
            }

            if ($argType->isNull()->yes()) {
                return $calledOnType->withSingleModel(TrinaryLogic::createYes());
            }

            return $calledOnType->withSingleModel(TrinaryLogic::createMaybe());
        }

        // Unlike upstream, which keeps the receiver's count, new() starts a factory without a count, and
        // forEachSequence() sets one.
        if ($isBaseMethod && $methodName === 'new') {
            return $calledOnType->withSingleModel(TrinaryLogic::createYes());
        }

        if ($isBaseMethod && $methodName === 'forEachSequence') {
            return $calledOnType->withSingleModel(TrinaryLogic::createNo());
        }

        $originalReturnType = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())->getReturnType();

        if (in_array($methodName, ['create', 'createQuietly', 'make'], true)) {
            if (! $isBaseMethod) {
                return null;
            }

            if ($calledOnType->isSingleModel()->yes()) {
                return TypeCombinator::remove($originalReturnType, new ObjectType(Collection::class));
            }

            if ($calledOnType->isSingleModel()->no()) {
                return TypeCombinator::remove($originalReturnType, new ObjectType(Model::class));
            }
        }

        // Unlike upstream, only a method returning the factory's own class keeps its count: a broader return,
        // such as mixed or another factory, is not this factory.
        if ($originalReturnType->getObjectClassNames() !== [$calledOnType->getClassName()]
            || ! $originalReturnType->isSuperTypeOf($calledOnType)->yes()) {
            return null;
        }

        // A factory's own count(), times(), new() or forEachSequence() may set the count differently. Returning
        // null would not help: PHPStan resolves a declared static return to the receiver, with its old count.
        if (in_array($methodName, ['count', 'times', 'new', 'forEachSequence'], true)) {
            return $calledOnType->withSingleModel(TrinaryLogic::createMaybe());
        }

        return $calledOnType;
    }
}
