<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\ConsoleCommand;

use Hypervel\Console\Command;
use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function array_unique;
use function count;

#[AutowiredService]
class HasArgumentDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConsoleApplicationResolver $consoleApplicationResolver)
    {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return Command::class;
    }

    /**
     * Determine whether the method is the command's hasArgument().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // Unlike upstream, a command's own override keeps its declaration.
        return $methodReflection->getName() === 'hasArgument'
            && $methodReflection->getDeclaringClass()->getName() === Command::class;
    }

    /**
     * Get whether the command defines the given argument.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        if ($methodCall->getArgs() === []) {
            return null;
        }

        $constantStrings = $scope->getType($methodCall->getArgs()[0]->value)->getConstantStrings();

        if (count($constantStrings) !== 1) {
            return null;
        }

        $returnTypes = [];

        // Upstream reads the enclosing class, which mistypes calls on another command.
        foreach ($this->consoleApplicationResolver->findCommandsForType($scope->getType($methodCall->var), $scope) as $command) {
            $returnTypes[] = $command->getDefinition()->hasArgument($constantStrings[0]->getValue());
        }

        if (count($returnTypes) === 0) {
            return null;
        }

        $returnTypes = array_unique($returnTypes);

        return count($returnTypes) === 1 ? new ConstantBooleanType($returnTypes[0]) : null;
    }
}
