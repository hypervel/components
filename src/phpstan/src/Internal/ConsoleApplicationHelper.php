<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Internal;

use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function count;

#[AutowiredService]
final class ConsoleApplicationHelper
{
    /**
     * Get the arguments array type of the given commands.
     *
     * @param array<int, Command> $commands
     */
    public function getArguments(array $commands, Scope $scope): ?Type
    {
        $argTypes = [];

        foreach ($commands as $command) {
            $arguments = $command->getDefinition()->getArguments();
            $builder = ConstantArrayTypeBuilder::createEmpty();

            foreach ($arguments as $name => $argument) {
                $argumentType = $this->getArgumentType($scope, $argument);
                $builder->setOffsetValueType(new ConstantStringType($name), $argumentType);
            }

            $argTypes[] = $builder->getArray();
        }

        return count($argTypes) > 0 ? TypeCombinator::union(...$argTypes) : null;
    }

    /**
     * Get the type of the given command argument.
     */
    public function getArgumentType(Scope $scope, InputArgument $argument): Type
    {
        if ($argument->isArray()) {
            $argType = new ArrayType(new IntegerType, new StringType);

            if (! $argument->isRequired() && $argument->getDefault() !== []) {
                $argType = TypeCombinator::union($argType, $scope->getTypeFromValue($argument->getDefault()));
            }
        } else {
            $argType = new StringType;

            if (! $argument->isRequired()) {
                $argType = TypeCombinator::union($argType, $scope->getTypeFromValue($argument->getDefault()));
            }
        }

        return $argType;
    }

    /**
     * Get the options array type of the given commands.
     *
     * @param array<int, Command> $commands
     */
    public function getOptions(array $commands, Scope $scope): ?Type
    {
        $optTypes = [];

        foreach ($commands as $command) {
            $options = $command->getDefinition()->getOptions();
            $builder = ConstantArrayTypeBuilder::createEmpty();

            foreach ($options as $name => $option) {
                $argumentType = $this->getOptionType($scope, $option);
                $builder->setOffsetValueType(new ConstantStringType($name), $argumentType);
            }

            $optTypes[] = $builder->getArray();
        }

        return count($optTypes) > 0 ? TypeCombinator::union(...$optTypes) : null;
    }

    /**
     * Get the type of the given command option.
     */
    public function getOptionType(Scope $scope, InputOption $option): Type
    {
        if (! $option->acceptValue()) {
            if ($option->isNegatable()) {
                return TypeCombinator::addNull(new BooleanType);
            }

            return new BooleanType;
        }

        $optType = TypeCombinator::union(new StringType, new NullType);

        if ($option->isValueRequired() && ($option->isArray() || $option->getDefault() !== null)) {
            $optType = TypeCombinator::removeNull($optType);
        }

        if ($option->isArray()) {
            $optType = new ArrayType(new IntegerType, $optType);
        }

        return TypeCombinator::union($optType, $scope->getTypeFromValue($option->getDefault()));
    }
}
