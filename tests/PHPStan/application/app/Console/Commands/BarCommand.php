<?php

namespace App\Console\Commands;

use Hypervel\Console\Command;

use function PHPStan\Testing\assertType;

class BarCommand extends Command
{
    protected ?string $signature = 'bar {argumentArray*} {--optionArray=*}';

    protected string $description = 'Dummy command used to test console command types.';

    public function handle(): void
    {
        assertType('array{command: string, argumentArray: array<int, string>}', $this->argument());
        assertType('array{command: string, argumentArray: array<int, string>}', $this->arguments());
        assertType('array<int, string>', $this->argument('argumentArray'));

        assertType('true', $this->hasArgument('argumentArray'));

        assertType('array{optionArray: array<int, string|null>, disable-event-dispatcher: bool, help: bool, silent: bool, quiet: bool, verbose: bool, version: bool, ansi: bool|null, no-interaction: bool, env: string|null}', $this->options());
        assertType('array{optionArray: array<int, string|null>, disable-event-dispatcher: bool, help: bool, silent: bool, quiet: bool, verbose: bool, version: bool, ansi: bool|null, no-interaction: bool, env: string|null}', $this->option());
        assertType('array<int, string|null>', $this->option('optionArray'));

        assertType('true', $this->hasOption('optionArray'));
    }
}
