<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\ConsoleCommand;

use Hypervel\Console\Command;
use Hypervel\PHPStan\Internal\ConsoleApplicationHelper;
use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use InvalidArgumentException;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function in_array;

#[AutowiredService]
class OptionDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConsoleApplicationResolver $consoleApplicationResolver, private ConsoleApplicationHelper $consoleApplicationHelper)
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
     * Determine whether the method reads command options.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // A command's own override keeps its declaration.
        return in_array($methodReflection->getName(), ['option', 'options'], true)
            && $methodReflection->getDeclaringClass()->getName() === Command::class;
    }

    /**
     * Get the type of the requested command options.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        // Upstream reads the enclosing class, which mistypes calls on another command.
        $commands = $this->consoleApplicationResolver->findCommandsForType($scope->getType($methodCall->var));

        if ($commands === []) {
            return null;
        }

        $args = $methodCall->getArgs();

        if ($args === []
            || $methodReflection->getName() === 'options'
            || (! $args[0]->unpack && $scope->getType($args[0]->value)->isNull()->yes())
        ) {
            return $this->consoleApplicationHelper->getOptions($commands, $scope);
        }

        $argStrings = $scope->getType($args[0]->value)->getConstantStrings();

        if (count($argStrings) === 0) {
            return null;
        }

        $defaultReturnType = ParametersAcceptorSelector::selectFromArgs($scope, $args, $methodReflection->getVariants())->getReturnType();

        $returnTypes = [];

        foreach ($argStrings as $argString) {
            $argName = $argString->getValue();

            $argTypes = [];

            foreach ($commands as $command) {
                try {
                    $definition = $command->getDefinition();

                    // Input::getOption() reads negations such as no-ansi, but not the shortcuts that
                    // upstream accepted. A negatable option takes no value, so the type stays bool|null.
                    $argument = $definition->getOption($definition->hasNegation($argName) ? $definition->negationToName($argName) : $argName);

                    $argTypes[] = $this->consoleApplicationHelper->getOptionType($scope, $argument);
                } catch (InvalidArgumentException) {
                    // A command without the option throws rather than returning a value.
                }
            }

            // Upstream added the partial union for each command, so the result depended on their order.
            $returnTypes[] = count($argTypes) > 0 ? TypeCombinator::union(...$argTypes) : $defaultReturnType;
        }

        return TypeCombinator::union(...$returnTypes);
    }
}
