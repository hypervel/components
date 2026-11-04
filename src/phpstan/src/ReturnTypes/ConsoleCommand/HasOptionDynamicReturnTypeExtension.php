<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\ConsoleCommand;

use Hypervel\Console\Command;
use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function array_unique;
use function count;

#[AutowiredService]
class HasOptionDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
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
     * Determine whether the method is the command's hasOption().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // A command's own override keeps its declaration.
        return $methodReflection->getName() === 'hasOption'
            && $methodReflection->getDeclaringClass()->getName() === Command::class;
    }

    /**
     * Get whether the command defines the given option or negation.
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

        $argName = $constantStrings[0]->getValue();

        $returnTypes = [];

        // Upstream reads the enclosing class, which mistypes calls on another command.
        foreach ($this->consoleApplicationResolver->findCommandsForType($scope->getType($methodCall->var)) as $command) {
            // Input::hasOption() accepts negations such as no-ansi, but not the shortcuts that upstream accepted.
            $returnTypes[] = $command->getDefinition()->hasOption($argName) || $command->getDefinition()->hasNegation($argName);
        }

        if (count($returnTypes) === 0) {
            return null;
        }

        $returnTypes = array_unique($returnTypes);

        return count($returnTypes) === 1 ? new ConstantBooleanType($returnTypes[0]) : null;
    }
}
