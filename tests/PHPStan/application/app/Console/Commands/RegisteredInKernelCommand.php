<?php

namespace App\Console\Commands;

use Hypervel\Console\Command;

use function PHPStan\Testing\assertType;

class RegisteredInKernelCommand extends Command
{
    protected ?string $signature = 'registered-in-kernel {argument} {--option=}';

    protected string $description = 'Dummy command registered only with the console kernel, which discovers app/Console/Commands.';

    public function handle(): void
    {
        assertType('array{command: string, argument: string}', $this->argument());
        assertType('string', $this->argument('argument'));
        assertType('string|null', $this->option('option'));

        assertType('true', $this->hasArgument('argument'));
        assertType('false', $this->hasArgument('undefinedArgument'));
        assertType('true', $this->hasOption('option'));
    }

    public function undefinedInput(): void
    {
        $this->argument('undefinedArgument');
        $this->option('undefinedOption');
    }
}
