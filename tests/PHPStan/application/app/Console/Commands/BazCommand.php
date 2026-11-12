<?php

namespace App\Console\Commands;

use Hypervel\Console\Command;

use function PHPStan\Testing\assertType;

class BazCommand extends Command
{
    protected ?string $signature = 'baz {optionalArgumentArray?*}';

    protected string $description = 'Dummy command used to test console command types.';

    public function handle(): void
    {
        assertType('array{command: string, optionalArgumentArray: array<int, string>}', $this->argument());
        assertType('array{command: string, optionalArgumentArray: array<int, string>}', $this->arguments());
        assertType('array<int, string>', $this->argument('optionalArgumentArray'));

        assertType('true', $this->hasArgument('optionalArgumentArray'));
    }
}
