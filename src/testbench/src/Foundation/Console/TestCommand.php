<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Dotenv\Exception\InvalidPathException;
use Dotenv\Parser\Parser;
use Dotenv\Store\StoreBuilder;
use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Testbench\Features\ParallelRunner;
use Hypervel\Testbench\Foundation\Env;
use NunoMaduro\Collision\Adapters\Laravel\Exceptions\RequirementsException;
use NunoMaduro\Collision\Coverage;
use Override;
use ParaTest\Options;
use RuntimeException;
use SebastianBergmann\Environment\Console;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

use function Hypervel\Testbench\defined_environment_variables;
use function Hypervel\Testbench\is_testbench_cli;
use function Hypervel\Testbench\package_path;

#[AsCommand(name: 'package:test', description: 'Run the package tests')]
class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:test
        {--without-tty : Disable output to TTY}
        {--compact : Indicates whether the compact printer should be used}
        {--configuration= : Read configuration from XML file}
        {--coverage : Indicates whether the coverage information should be collected}
        {--min= : Indicates the minimum threshold enforcement for coverage}
        {--p|parallel : Indicates if the tests should run in parallel}
        {--profile : Lists top 10 slowest tests}
        {--recreate-databases : Indicates if the test databases should be re-created}
        {--drop-databases : Indicates if the test databases should be dropped}
        {--without-databases : Indicates if database configuration should be performed}
        {--c|--custom-argument : Add custom env variables}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Run the package tests';

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    #[Override]
    public function configure(): void
    {
        parent::configure();

        if (! is_testbench_cli()) {
            $this->setHidden(true);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Env::enablePutenv();

        if ($this->option('coverage') && ! Coverage::isAvailable()) {
            $this->output->writeln(sprintf(
                "\n  <fg=white;bg=red;options=bold> ERROR </> Code coverage driver not available.%s</>",
                Coverage::usingXdebug()
                    ? " Did you set <href=https://xdebug.org/docs/code_coverage#mode>Xdebug's coverage mode</>?"
                    : ' Did you install <href=https://xdebug.org/>Xdebug</> or <href=https://github.com/krakjoe/pcov>PCOV</>?'
            ));

            $this->newLine();

            return self::FAILURE;
        }

        /** @var bool $usesParallel */
        $usesParallel = $this->option('parallel');

        if ($usesParallel && ! $this->isParallelDependenciesInstalled()) {
            throw new RequirementsException(
                'Running Hypervel package:test in parallel requires ParaTest (brianium/paratest) 7.x or newer.'
            );
        }

        $options = array_slice($_SERVER['argv'], $this->option('without-tty') ? 3 : 2);

        $this->clearEnv();

        /** @var bool $parallel */
        $parallel = $this->option('parallel');

        $process = (new Process(
            command: array_merge(
                $this->binary(),
                $parallel ? $this->paratestArguments($options) : $this->phpunitArguments($options)
            ),
            env: $parallel ? $this->paratestEnvironmentVariables() : $this->phpunitEnvironmentVariables(),
        ))->setTimeout(null);

        try {
            $process->setTty(! $this->option('without-tty'));
        } catch (RuntimeException) {
        }

        $exitCode = self::FAILURE;

        try {
            $exitCode = $process->run(function (string $type, string $line): void {
                $this->output->write($line);
            });
        } catch (ProcessSignaledException $exception) {
            if (extension_loaded('pcntl') && $exception->getSignal() !== SIGINT) {
                throw $exception;
            }
        }

        if ($exitCode === self::SUCCESS && $this->option('coverage')) {
            if (! $this->usingPest() && $parallel) {
                $this->newLine();
            }

            /** @var bool $compact */
            $compact = $this->option('compact');
            $coverage = Coverage::report($this->output, $compact);

            /** @var null|string $minimumCoverage */
            $minimumCoverage = $this->option('min');
            $minimumCoverageValue = (float) ($minimumCoverage ?? 0);

            $exitCode = (int) ($coverage < $minimumCoverageValue);

            if ($exitCode === self::FAILURE) {
                $this->output->writeln(sprintf(
                    "\n  <fg=white;bg=red;options=bold> FAIL </> Code coverage below expected:<fg=red;options=bold> %s %%</>. Minimum:<fg=white;options=bold> %s %%</>.",
                    number_format($coverage, 1),
                    number_format($minimumCoverageValue, 1)
                ));
            }
        }

        return $exitCode;
    }

    /**
     * Get the PHP binary to execute.
     *
     * @return array<int, string>
     */
    protected function binary(): array
    {
        if ($this->usingPest()) {
            $command = $this->option('parallel')
                ? [package_path('vendor', 'pestphp', 'pest', 'bin', 'pest'), '--parallel']
                : [package_path('vendor', 'pestphp', 'pest', 'bin', 'pest')];
        } else {
            $command = $this->option('parallel')
                ? [package_path('vendor', 'brianium', 'paratest', 'bin', 'paratest')]
                : [package_path('vendor', 'phpunit', 'phpunit', 'phpunit')];
        }

        if (PHP_SAPI === 'phpdbg') {
            return array_merge([PHP_BINARY, '-qrr'], $command);
        }

        return array_merge([PHP_BINARY], $command);
    }

    /**
     * Get the common arguments of PHPUnit and Pest.
     *
     * @return array<int, string>
     */
    protected function commonArguments(): array
    {
        $arguments = [];

        if ($this->option('coverage')) {
            $arguments[] = '--coverage-php';
            $arguments[] = Coverage::getPath();
        }

        if ($this->option('ansi')) {
            $arguments[] = '--colors=always';
        } elseif ($this->option('no-ansi')) {
            $arguments[] = '--colors=never';
        } elseif ((new Console)->hasColorSupport()) {
            $arguments[] = '--colors=always';
        }

        return $arguments;
    }

    /**
     * Determine if Pest is being used.
     */
    protected function usingPest(): bool
    {
        return function_exists('\Pest\version');
    }

    /**
     * Get the PHPUnit configuration file path.
     */
    public function phpUnitConfigurationFile(): string
    {
        /** @var null|string $configuration */
        $configuration = $this->option('configuration');
        $configurationFile = str_replace('./', '', $configuration ?? 'phpunit.xml');

        return (new Collection([
            package_path($configurationFile),
            package_path("{$configurationFile}.dist"),
        ]))->filter(static fn (string $path): bool => is_file($path))
            ->first() ?? './';
    }

    /**
     * Get the array of arguments for running PHPUnit.
     *
     * @param array<int, string> $options
     * @return array<int, string>
     */
    protected function phpunitArguments(array $options): array
    {
        $file = $this->phpUnitConfigurationFile();

        $filteredOptions = (new Collection(array_merge(['--no-output'], $options)))
            ->filter(static function (string $option): bool {
                return ! Str::startsWith($option, '--env=')
                    && $option !== '-q'
                    && $option !== '--quiet'
                    && $option !== '--coverage'
                    && $option !== '--compact'
                    && $option !== '--profile'
                    && $option !== '--ansi'
                    && $option !== '--no-ansi'
                    && ! Str::startsWith($option, '--min')
                    && ! Str::startsWith($option, '--configuration=');
            })->values()
            ->all();

        return array_merge($this->commonArguments(), ["--configuration={$file}"], $filteredOptions);
    }

    /**
     * Get the configuration file.
     */
    protected function getConfigurationFile(): string
    {
        return $this->phpUnitConfigurationFile();
    }

    /**
     * Get the array of arguments for running Paratest.
     *
     * @param array<int, string> $options
     * @return array<int, string>
     */
    protected function paratestArguments(array $options): array
    {
        $file = $this->phpUnitConfigurationFile();

        $filteredOptions = (new Collection($options))
            ->filter(static function (string $option): bool {
                return ! Str::startsWith($option, '--env=')
                    && $option !== '--coverage'
                    && $option !== '-q'
                    && $option !== '--quiet'
                    && $option !== '--ansi'
                    && $option !== '--no-ansi'
                    && ! Str::startsWith($option, '--min')
                    && ! Str::startsWith($option, '-p')
                    && ! Str::startsWith($option, '--compact')
                    && ! Str::startsWith($option, '--parallel')
                    && ! Str::startsWith($option, '--recreate-databases')
                    && ! Str::startsWith($option, '--drop-databases')
                    && ! Str::startsWith($option, '--without-databases')
                    && ! Str::startsWith($option, '--configuration=')
                    && ! Str::startsWith($option, '--runner=');
            })->values()
            ->all();

        $arguments = array_merge(
            $this->commonArguments(),
            [sprintf('--configuration=%s', $file), sprintf('--runner=%s', ParallelRunner::class)],
            $filteredOptions
        );

        $inputDefinition = new InputDefinition;
        Options::setInputDefinition($inputDefinition);
        $input = new ArgvInput($arguments, $inputDefinition);
        $paraTestOptions = Options::fromConsoleInput($input, package_path());

        if (! $paraTestOptions->configuration->hasCoverageCacheDirectory()) {
            $arguments[] = '--cache-directory';
            $arguments[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '__hypervel_test_cache_directory';
        }

        return $arguments;
    }

    /**
     * Get the array of environment variables for running PHPUnit.
     *
     * @return array<string, null|bool|int|string>
     */
    protected function phpunitEnvironmentVariables(): array
    {
        return (new Collection(defined_environment_variables()))
            ->merge([
                'APP_ENV' => 'testing',
                'COLLISION_PRINTER' => 'DefaultPrinter',
                'TESTBENCH_PACKAGE_TESTER' => '(true)',
                'TESTBENCH_WORKING_PATH' => package_path(),
                'TESTBENCH_APP_BASE_PATH' => $this->hypervel->basePath(),
            ])->when(
                $this->option('compact'),
                static fn (Collection $variables): Collection => $variables->put('COLLISION_PRINTER_COMPACT', 'true')
            )->when(
                $this->option('profile'),
                static fn (Collection $variables): Collection => $variables->put('COLLISION_PRINTER_PROFILE', 'true')
            )->all();
    }

    /**
     * Get the array of environment variables for running Paratest.
     *
     * @return array<string, null|bool|int|string>
     */
    protected function paratestEnvironmentVariables(): array
    {
        return (new Collection(defined_environment_variables()))
            ->merge([
                'APP_ENV' => 'testing',
                'TESTBENCH_PACKAGE_TESTER' => '(true)',
                'TESTBENCH_WORKING_PATH' => package_path(),
                'TESTBENCH_APP_BASE_PATH' => $this->hypervel->basePath(),
                'HYPERVEL_PARALLEL_TESTING' => 1,
                'HYPERVEL_PARALLEL_TESTING_RECREATE_DATABASES' => $this->option('recreate-databases'),
                'HYPERVEL_PARALLEL_TESTING_DROP_DATABASES' => $this->option('drop-databases'),
                'HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES' => $this->option('without-databases'),
            ])->all();
    }

    /**
     * Clear any set environment variables if the --env option is empty.
     */
    protected function clearEnv(): void
    {
        if (! $this->option('env')) {
            $variables = self::getEnvironmentVariables(
                $this->hypervel->environmentPath(),
                $this->hypervel->environmentFile()
            );

            $repository = Env::getRepository();

            foreach ($variables as $name) {
                $repository->clear($name);
            }
        }
    }

    /**
     * Get the environment variable names from the configured dotenv file.
     *
     * @return array<int, string>
     */
    protected static function getEnvironmentVariables(string $path, string $file): array
    {
        try {
            $content = StoreBuilder::createWithNoNames()
                ->addPath($path)
                ->addName($file)
                ->make()
                ->read();
        } catch (InvalidPathException) {
            return [];
        }

        $variables = [];

        foreach ((new Parser)->parse($content) as $entry) {
            $variables[] = $entry->getName();
        }

        return $variables;
    }

    /**
     * Check if the parallel dependencies are installed.
     */
    protected function isParallelDependenciesInstalled(): bool
    {
        return class_exists(\ParaTest\ParaTestCommand::class);
    }
}
