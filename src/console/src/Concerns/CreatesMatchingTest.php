<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Hypervel\Support\Stringable;
use Symfony\Component\Console\Input\InputOption;

trait CreatesMatchingTest
{
    /**
     * Add the standard command options for generating matching tests.
     */
    protected function addTestOptions(): void
    {
        foreach (['test', 'phpunit'] as $option) {
            $this->getDefinition()->addOption(new InputOption(
                $option,
                null,
                InputOption::VALUE_NONE,
                "Generate an accompanying PHPUnit test for the {$this->type}"
            ));
        }
    }

    /**
     * Create the matching test case if requested.
     */
    protected function handleTestCreation(string $path): bool
    {
        if (! $this->option('test') && ! $this->option('phpunit')) {
            return false;
        }

        return $this->call('make:test', [
            'name' => (new Stringable($path))->after($this->hypervel->make('path'))->beforeLast('.php')->append('Test')->replace('\\', '/')->value(),
            '--force' => $this->hasOption('force') && $this->option('force'),
        ]) === 0;
    }
}
