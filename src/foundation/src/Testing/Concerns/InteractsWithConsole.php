<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Testing\PendingCommand;

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
     * All of the expected questions.
     */
    public array $expectedQuestions = [];

    /**
     * All of the expected choice questions.
     */
    public array $expectedChoices = [];

    /**
     * Invoke an Artisan command and return a mocked pending command or exit code.
     */
    public function artisan(string $command, array $parameters = []): int|PendingCommand
    {
        return $this->mockConsoleOutput
            ? $this->mockArtisan($command, $parameters)
            : $this->realArtisan($command, $parameters);
    }

    /**
     * Invoke an Artisan command and return a mocked pending command.
     */
    public function mockArtisan(string $command, array $parameters = []): PendingCommand
    {
        return new PendingCommand($this, $this->app, $command, $parameters);
    }

    /**
     * Invoke an Artisan command and return the exit code.
     */
    public function realArtisan(string $command, array $parameters = []): int
    {
        return $this->app
            ->get(KernelContract::class)
            ->call($command, $parameters);
    }

    /**
     * Disable mocking the console output.
     *
     * When using this with traits like DatabaseMigrations, call this in setUp()
     * before parent::setUp() to ensure mock output is never bound.
     */
    protected function withoutMockingConsoleOutput(): static
    {
        $this->mockConsoleOutput = false;

        return $this;
    }
}
