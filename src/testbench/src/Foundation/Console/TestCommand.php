<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Dotenv\Parser\Parser;
use Dotenv\Store\StringStore;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Features\ParallelRunner;
use Hypervel\Testing\Console\TestCommandBase;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Testbench\is_testbench_cli;
use function Hypervel\Testbench\package_path;

#[AsCommand(name: 'package:test', description: 'Run the package tests')]
class TestCommand extends TestCommandBase
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:test
        {--without-tty : Disable output to TTY}
        {--configuration= : Read configuration from XML file}
        {--coverage : Indicates whether coverage information should be collected}
        {--min= : Indicates the minimum threshold enforcement for coverage}
        {--p|parallel : Indicates if the tests should run in parallel}
        {--profile : Lists top 10 slowest tests}
        {--recreate-databases : Indicates if the test databases should be re-created}
        {--drop-databases : Indicates if the test databases should be dropped}
        {--without-databases : Indicates if database configuration should be performed}
        {--without-cache : Indicates if cache configuration should be performed}
        {--c|--custom-argument : Add custom env variables}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Run the package tests';

    #[Override]
    public function configure(): void
    {
        parent::configure();

        if (! is_testbench_cli()) {
            $this->setHidden(true);
        }
    }

    /**
     * Get the base environment variables.
     *
     * @return Collection<string, null|bool|int|string>
     */
    #[Override]
    protected function baseEnvironmentVariables(): Collection
    {
        return (new Collection($this->configurationEnvironmentVariables()))->merge(parent::baseEnvironmentVariables())->merge([
            'TESTBENCH_PACKAGE_TESTER' => '(true)',
            'TESTBENCH_WORKING_PATH' => package_path(),
        ]);
    }

    /**
     * Get configured Testbench environment variables.
     *
     * @return array<string, string>
     */
    protected function configurationEnvironmentVariables(): array
    {
        $environmentVariables = Bootstrapper::getConfig()['env'] ?? [];

        if (! is_array($environmentVariables) || $environmentVariables === []) {
            return [];
        }

        $store = new StringStore(implode(PHP_EOL, $environmentVariables));
        $parser = new Parser;
        $variables = [];

        foreach ($parser->parse($store->read()) as $entry) {
            if ($entry->getValue()->isDefined()) {
                $variables[$entry->getName()] = $entry->getValue()->get()->getChars();
            }
        }

        return $variables;
    }

    /**
     * Get the parallel runner class.
     *
     * @return class-string
     */
    #[Override]
    protected function parallelRunner(): string
    {
        return ParallelRunner::class;
    }

    /**
     * Get an absolute path relative to the package root.
     */
    #[Override]
    protected function basePath(string ...$paths): string
    {
        return package_path(...$paths);
    }
}
