<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;

trait InteractsWithConsole
{
    /**
     * Indicates if the console output should be mocked.
     */
    public bool $mockConsoleOutput = true;

    /**
     * Alias of `command` method.
     */
    public function artisan(string $command, array $parameters = []): int
    {
        return $this->command($command, $parameters);
    }

    /**
     * Call hyperf command and return code.
     */
    public function command(string $command, array $parameters = []): int
    {
        return $this->app->get(KernelContract::class)
            ->call($command, $parameters);
    }

    /**
     * Disable mocking the console output.
     *
     * @return $this
     */
    protected function withoutMockingConsoleOutput(): static
    {
        $this->mockConsoleOutput = false;

        return $this;
    }
}
