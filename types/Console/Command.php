<?php

declare(strict_types=1);

use Hypervel\Console\Application;
use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\ContainerCommandLoader;
use Hypervel\Console\GeneratorCommand;
use Hypervel\Console\Parser;
use Hypervel\Container\Container;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use function PHPStan\Testing\assertType;

[$name, $arguments, $options] = Parser::parse('example {name?} {--force}');
assertType('string', $name);
assertType('array<Symfony\Component\Console\Input\InputArgument>', $arguments);
assertType('array<Symfony\Component\Console\Input\InputOption>', $options);

$command = new ConsoleTypingCommand('example');
assertType('array', $command->argument());
assertType('array', $command->option());
assertType('mixed', $command->argument('name'));
assertType('mixed', $command->option('force'));
assertType('true', $command->confirmToProceed(callback: false));

assertType('array{1, 2, 3}', $command->withProgressBar([1, 2, 3], fn (int $value): int => $value));
assertType('null', $command->withProgressBar(3, fn (ProgressBar $bar): int => $bar->getProgress()));

$generator = (function (): Generator {
    yield 'first' => 'value';
})();
assertType("Generator<'first', 'value', mixed, void>", $command->withProgressBar($generator, fn (string $value): string => $value));

$command->withProgressBar(['value'], fn (int $value): int => $value); // @phpstan-ignore argument.type

// These supported callbacks and output styles must not be narrowed by the port.
$command->trap(15, fn (): bool => false);
$command->line('example', 'fg=green');
$command->info('example', 'custom');
$command->info('example', 100);
Application::starting(fn (Application $application): Application => $application);

// Command loaders accept container service IDs as well as class names.
$container = new Container;
$loader = new ContainerCommandLoader($container, ['example' => 'console.example']);

class ConsoleTypingCommand extends Command
{
    use ConfirmableTrait;

    protected array $verbosityMap = ['custom' => 100];

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', null, 'An optional argument', null, fn (CompletionInput $input): array => ['example']],
            ['items', InputArgument::OPTIONAL | InputArgument::IS_ARRAY],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['force', null, null],
            ['values', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', null, fn (CompletionInput $input): array => ['example']],
        ];
    }
}

abstract class ConsoleTypingGenerator extends GeneratorCommand
{
    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', null],
            ['items', InputArgument::OPTIONAL | InputArgument::IS_ARRAY],
        ];
    }
}

abstract class InvalidConsoleCompletionCommand extends Command
{
    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        // Symfony supplies only the input when invoking a completion callback.
        return [ // @phpstan-ignore return.type
            ['name', InputArgument::OPTIONAL, '', null, fn (CompletionInput $input, CompletionSuggestions $suggestions): array => ['example']],
        ];
    }
}
