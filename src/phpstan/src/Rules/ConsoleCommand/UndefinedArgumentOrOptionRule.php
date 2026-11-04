<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\ConsoleCommand;

use Hypervel\Console\Command;
use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function count;
use function in_array;
use function sprintf;

/** @implements Rule<MethodCall> */
#[AutowiredService]
final class UndefinedArgumentOrOptionRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private ConsoleApplicationResolver $consoleApplicationResolver)
    {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report arguments and options that the command does not define.
     *
     * @return RuleError[] errors
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || ! in_array($node->name->toLowerString(), ['argument', 'option'], true)) {
            return [];
        }

        if (count($node->getArgs()) !== 1) {
            return [];
        }

        $receiverType = $scope->getType($node->var);

        if (! (new ObjectType(Command::class))->isSuperTypeOf($receiverType)->yes()) {
            return [];
        }

        $methodName = $node->name->toLowerString();

        // Unlike upstream, a command's own override decides which names it accepts.
        if ($scope->getMethodReflection($receiverType, $methodName)?->getDeclaringClass()->getName() !== Command::class) {
            return [];
        }

        $value = $node->getArgs()[0]->value;

        $argStrings = $scope->getType($value)->getConstantStrings();

        if (count($argStrings) !== 1) {
            return [];
        }

        // A definitely true or unreachable hasArgument()/hasOption() guard keeps the call from commands
        // without the name. The scope keys the guard as written, so check its positional and named forms.
        $guardMethod = $methodName === 'argument' ? 'hasArgument' : 'hasOption';

        foreach ([new Arg($value), new Arg($value, name: new Identifier('name'))] as $guardArg) {
            if ($scope->getType(new MethodCall($node->var, $guardMethod, [$guardArg]))->isFalse()->no()) {
                return [];
            }
        }

        $argName = $argStrings[0]->getValue();

        $errors = [];

        // Upstream checks the enclosing command, which misreports calls on another command.
        foreach ($this->consoleApplicationResolver->findCommandsForType($receiverType) as $command) {
            $name = $command->getName();

            if ($methodName === 'argument') {
                if (! $command->getDefinition()->hasArgument($argName)) {
                    $errors[] = RuleErrorBuilder::message(sprintf('Command "%s" does not have argument "%s".', $name, $argName))
                        ->line($node->getStartLine())
                        ->identifier('hypervel.console.undefinedArgument')
                        ->build();
                }
            } elseif (! $command->getDefinition()->hasOption($argName) && ! $command->getDefinition()->hasNegation($argName)) {
                // Input::getOption() reads negations such as no-ansi, but throws for the shortcuts that upstream accepted.
                $errors[] = RuleErrorBuilder::message(sprintf('Command "%s" does not have option "%s".', $name, $argName))
                    ->line($node->getStartLine())
                    ->identifier('hypervel.console.undefinedOption')
                    ->build();
            }
        }

        return $errors;
    }
}
