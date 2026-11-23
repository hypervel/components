<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\PHPStan\Types\Factory\ModelFactoryType;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\TrinaryLogic;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function count;
use function in_array;

/**
 * Record whether a factory created through new() or times() makes one model or several.
 */
#[AutowiredService]
class FactoryDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Factory::class;
    }

    /**
     * Determine whether the static method is the base factory's new() or times().
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        // A factory's own implementation keeps its declaration.
        return $methodReflection->getDeclaringClass()->getName() === Factory::class
            && in_array($methodReflection->getName(), ['new', 'times'], true);
    }

    /**
     * Get the factory, which new() creates without a count and times() with one.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        // self::, static:: and parent:: forward the late static class, so their declared static stays.
        if (! $methodCall->class instanceof Name || $methodCall->class->isSpecialClassName()) {
            return null;
        }

        // A missing count keeps the declaration beside PHPStan's own report.
        if ($methodReflection->getName() === 'times' && $methodCall->getArgs() === []) {
            return null;
        }

        $factoryReflections = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())
            ->getReturnType()
            ->getObjectClassReflections();

        // A factory that declares its own templates keeps its generic declaration, as in Model::factory().
        if (count($factoryReflections) !== 1 || $factoryReflections[0]->isGeneric()) {
            return null;
        }

        return new ModelFactoryType(
            $factoryReflections[0]->getName(),
            null,
            $factoryReflections[0],
            TrinaryLogic::createFromBoolean($methodReflection->getName() === 'new'),
        );
    }
}
