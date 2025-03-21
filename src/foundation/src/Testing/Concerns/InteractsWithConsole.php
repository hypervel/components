<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Hypervel\Foundation\Testing\PendingCommand;

trait InteractsWithConsole
{
    /**
     * Indicates if the console output should be mocked.
     */
    public bool $mockConsoleOutput = true;

    /**
     * Indicates if the command is expected to output anything.
     */
    public ?bool $expectsOutput = null;

    /**
     * All of the expected output lines.
     */
    public array $expectedOutput = [];

    /**
     * All of the expected text to be present in the output.
     */
    public array $expectedOutputSubstrings = [];

    /**
     * All of the output lines that aren't expected to be displayed.
     */
    public array $unexpectedOutput = [];

    /**
     * All of the text that is not expected to be present in the output.
     */
    public array $unexpectedOutputSubstrings = [];

    /**
     * All of the expected output tables.
     */
    public array $expectedTables = [];

    /**
     * All of the expected questions.
     */
    public array $expectedQuestions = [];

    /**
     * All of the expected choice questions.
     */
    public array $expectedChoices = [];

    /**
     * Alias of `command` method.
     */
    public function artisan(string $command, array $parameters = []): int|PendingCommand
    {
        return $this->command($command, $parameters);
    }

    /**
     * Call Hypervel command and return code.
     */
    public function command(string $command, array $parameters = []): int|PendingCommand
    {
        if (! $this->mockConsoleOutput) {
            return $this->app
                ->get(KernelContract::class)
                ->call($command, $parameters);
        }

        return new PendingCommand($this, $this->app, $command, $parameters);
    }

    /**
     * Disable mocking the console output.
     */
    protected function withoutMockingConsoleOutput(): static
    {
        $this->mockConsoleOutput = false;

        return $this;
    }
}
