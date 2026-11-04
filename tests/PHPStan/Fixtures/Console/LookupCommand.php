<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Console;

use Hypervel\Console\Command;

use function PHPStan\Testing\assertType;

abstract class LookupCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // WithoutArgumentCommand is registered first and throws for the argument, so only
        // WithArgumentCommand's definition supplies the type.
        assertType('string', $this->argument('argument'));

        if ($this->hasArgument('argument')) {
            $this->argument('argument');
        }

        if ($this->hasOption('option')) {
            $this->option('option');
        }

        if ($this->hasArgument(name: 'argument')) {
            $this->argument('argument');
        }

        if ($this->hasOption('option')) {
            $this->option(key: 'option');
        }

        $this->hasArgument('missing') && $this->argument('missing');
    }
}
