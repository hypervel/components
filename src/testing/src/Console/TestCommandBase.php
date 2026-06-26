<?php

declare(strict_types=1);

namespace Hypervel\Testing\Console;

use DOMDocument;
use DOMElement;
use Dotenv\Exception\InvalidPathException;
use Dotenv\Parser\Parser;
use Dotenv\Store\StoreBuilder;
use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;
use Hypervel\Support\Str;
use Hypervel\Testing\Coverage;
use Hypervel\Testing\ParallelRunner;
use Hypervel\Testing\Profile\ProfileExtension;
use ParaTest\Options;
use RuntimeException;
use SebastianBergmann\Environment\Console;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

abstract class TestCommandBase extends Command
{
    public const string PROFILE_ENV = 'HYPERVEL_TEST_PROFILE';

    public const string PROFILE_DIRECTORY_ENV = 'HYPERVEL_TEST_PROFILE_DIRECTORY';

    /**
     * The coverage reporter.
     */
    protected ?Coverage $coverage = null;

    /**
     * The temporary profile directory.
     */
    protected ?string $profileDirectory = null;

    /**
     * The temporary PHPUnit configuration file.
     */
    protected ?string $temporaryConfigurationFile = null;

    /**
     * Create a new test command.
     */
    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
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

        if ($this->option('parallel') && ! $this->isParallelDependenciesInstalled()) {
            throw new RuntimeException('Running tests in parallel requires ParaTest (brianium/paratest) 7.x or newer.');
        }

        $options = array_slice($_SERVER['argv'], 2);

        $this->clearEnv();

        $parallel = (bool) $this->option('parallel');

        if ($this->option('profile')) {
            $this->ensureProfileDirectoryExists($this->profileDirectory());
        }

        $process = (new Process(
            command: array_merge(
                $this->binary(),
                $parallel ? $this->paratestArguments($options) : $this->phpunitArguments($options),
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
        } finally {
            $this->cleanupTemporaryConfigurationFile();
        }

        try {
            if ($this->option('profile')) {
                $this->reportProfile();
            }

            if ($exitCode === self::SUCCESS && $this->option('coverage')) {
                if (! $this->usingPest() && $parallel) {
                    $this->newLine();
                }

                $coverage = $this->coverage()->report($this->output);

                $minimumCoverage = $this->option('min');
                $minimumCoverageValue = (float) ($minimumCoverage ?? 0);

                $exitCode = (int) ($coverage < $minimumCoverageValue);

                if ($exitCode === self::FAILURE) {
                    $this->output->writeln(sprintf(
                        "\n  <fg=white;bg=red;options=bold> FAIL </> Code coverage below expected:<fg=red;options=bold> %s %%</>. Minimum:<fg=white;options=bold> %s %%</>.",
                        number_format($coverage, 1),
                        number_format($minimumCoverageValue, 1),
                    ));
                }
            }
        } finally {
            $this->coverage?->cleanup();
            $this->cleanupProfileDirectory();
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
                ? [$this->basePath('vendor', 'pestphp', 'pest', 'bin', 'pest'), '--parallel']
                : [$this->basePath('vendor', 'pestphp', 'pest', 'bin', 'pest')];
        } else {
            $command = $this->option('parallel')
                ? [$this->basePath('vendor', 'brianium', 'paratest', 'bin', 'paratest')]
                : [$this->basePath('vendor', 'phpunit', 'phpunit', 'phpunit')];
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
            $arguments[] = $this->coverage()->path();
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
        $configuration = $this->option('configuration');
        $configurationFile = str_replace('./', '', is_string($configuration) && $configuration !== '' ? $configuration : 'phpunit.xml');

        $file = (new Collection([
            $this->basePath($configurationFile),
            $this->basePath("{$configurationFile}.dist"),
        ]))->filter(static fn (string $path): bool => is_file($path))
            ->first() ?? './';

        return $this->option('profile') ? $this->profileConfigurationFile($file) : $file;
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

        $filteredOptions = $this->filterForwardedArguments(
            arguments: $options,
            exact: ['-q', '--quiet', '--without-tty', '--coverage', '--profile', '--ansi', '--no-ansi'],
            valueOptions: ['--env', '--min', '--configuration'],
        );

        return array_merge($this->commonArguments(), ["--configuration={$file}"], $filteredOptions);
    }

    /**
     * Get the array of arguments for running ParaTest.
     *
     * @param array<int, string> $options
     * @return array<int, string>
     */
    protected function paratestArguments(array $options): array
    {
        $file = $this->phpUnitConfigurationFile();

        $filteredOptions = $this->filterForwardedArguments(
            arguments: $options,
            exact: ['--coverage', '--profile', '-q', '--quiet', '--without-tty', '--ansi', '--no-ansi'],
            prefixes: ['-p', '--parallel', '--recreate-databases', '--drop-databases', '--without-databases', '--without-cache'],
            valueOptions: ['--env', '--min', '--configuration', '--runner'],
        );

        $arguments = array_merge(
            $this->commonArguments(),
            [sprintf('--configuration=%s', $file), sprintf('--runner=%s', $this->parallelRunner())],
            $filteredOptions,
        );

        $inputDefinition = new InputDefinition;
        Options::setInputDefinition($inputDefinition);
        $input = new ArgvInput(['paratest', ...$arguments], $inputDefinition);
        $paraTestOptions = Options::fromConsoleInput($input, $this->basePath());

        if (! $paraTestOptions->configuration->hasCoverageCacheDirectory()) {
            $arguments[] = '--cache-directory';
            $arguments[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '__hypervel_test_cache_directory';
        }

        return $arguments;
    }

    /**
     * Remove command-owned options before forwarding arguments to PHPUnit or ParaTest.
     *
     * @param array<int, string> $arguments
     * @param array<int, string> $exact
     * @param array<int, string> $prefixes
     * @param array<int, string> $valueOptions
     * @return array<int, string>
     */
    protected function filterForwardedArguments(array $arguments, array $exact = [], array $prefixes = [], array $valueOptions = []): array
    {
        $filtered = [];
        $skipNextArgument = false;

        foreach ($arguments as $argument) {
            if ($skipNextArgument) {
                $skipNextArgument = false;

                continue;
            }

            foreach ($valueOptions as $option) {
                if ($argument === $option) {
                    $skipNextArgument = true;

                    continue 2;
                }

                if (Str::startsWith($argument, "{$option}=")) {
                    continue 2;
                }
            }

            if (in_array($argument, $exact, true)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                if (Str::startsWith($argument, $prefix)) {
                    continue 2;
                }
            }

            $filtered[] = $argument;
        }

        return $filtered;
    }

    /**
     * Get the array of environment variables for running PHPUnit.
     *
     * @return array<string, null|bool|int|string>
     */
    protected function phpunitEnvironmentVariables(): array
    {
        return $this->baseEnvironmentVariables()
            ->when(
                $this->option('profile'),
                fn (Collection $variables): Collection => $variables
                    ->put(self::PROFILE_ENV, '1')
                    ->put(self::PROFILE_DIRECTORY_ENV, $this->profileDirectory()),
            )->all();
    }

    /**
     * Get the array of environment variables for running ParaTest.
     *
     * @return array<string, null|bool|int|string>
     */
    protected function paratestEnvironmentVariables(): array
    {
        return $this->baseEnvironmentVariables()
            ->merge([
                'HYPERVEL_PARALLEL_TESTING' => 1,
                'HYPERVEL_PARALLEL_TESTING_RECREATE_DATABASES' => $this->option('recreate-databases'),
                'HYPERVEL_PARALLEL_TESTING_DROP_DATABASES' => $this->option('drop-databases'),
                'HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES' => $this->option('without-databases'),
                'HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE' => $this->option('without-cache'),
            ])->when(
                $this->option('profile'),
                fn (Collection $variables): Collection => $variables
                    ->put(self::PROFILE_ENV, '1')
                    ->put(self::PROFILE_DIRECTORY_ENV, $this->profileDirectory()),
            )->all();
    }

    /**
     * Get the base environment variables.
     *
     * @return Collection<string, null|bool|int|string>
     */
    protected function baseEnvironmentVariables(): Collection
    {
        $environment = $this->option('env');

        return new Collection([
            'APP_ENV' => is_string($environment) && $environment !== '' ? $environment : 'testing',
        ]);
    }

    /**
     * Clear any set environment variables if the --env option is empty.
     */
    protected function clearEnv(): void
    {
        if ($this->option('env')) {
            return;
        }

        $path = $this->hypervel->environmentPath();

        if (! is_string($path)) {
            return;
        }

        $variables = self::getEnvironmentVariables($path, $this->hypervel->environmentFile());
        $repository = Env::getRepository();

        foreach ($variables as $name) {
            $repository->clear($name);
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

    /**
     * Get the parallel runner class.
     *
     * @return class-string
     */
    protected function parallelRunner(): string
    {
        return ParallelRunner::class;
    }

    /**
     * Get an absolute path relative to the project root.
     */
    protected function basePath(string ...$paths): string
    {
        return $this->hypervel->basePath(...$paths);
    }

    /**
     * Get the coverage reporter.
     */
    protected function coverage(): Coverage
    {
        return $this->coverage ??= new Coverage;
    }

    /**
     * Get the profile directory.
     */
    protected function profileDirectory(): string
    {
        return $this->profileDirectory ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypervel-test-profile-'
            . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Ensure the profile directory exists.
     */
    protected function ensureProfileDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create profile directory [%s].', $directory));
        }
    }

    /**
     * Add the profile extension to a temporary PHPUnit configuration file.
     */
    protected function profileConfigurationFile(string $file): string
    {
        if ($this->temporaryConfigurationFile !== null) {
            return $this->temporaryConfigurationFile;
        }

        if ($file === './') {
            return $file;
        }

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $document->load($file);

        $phpunit = $document->documentElement;

        if ($phpunit === null) {
            return $file;
        }

        $extensions = $phpunit->getElementsByTagName('extensions')->item(0);

        if (! $extensions instanceof DOMElement) {
            $extensions = $document->createElement('extensions');
            $phpunit->appendChild($extensions);
        }

        foreach ($extensions->getElementsByTagName('bootstrap') as $bootstrap) {
            if ($bootstrap->getAttribute('class') === ProfileExtension::class) {
                return $file;
            }
        }

        $bootstrap = $document->createElement('bootstrap');
        $bootstrap->setAttribute('class', ProfileExtension::class);
        $extensions->appendChild($bootstrap);

        $this->temporaryConfigurationFile = dirname($file) . DIRECTORY_SEPARATOR . '.hypervel-phpunit-profile-'
            . getmypid() . '-' . bin2hex(random_bytes(6)) . '.xml';

        $document->save($this->temporaryConfigurationFile);

        return $this->temporaryConfigurationFile;
    }

    /**
     * Remove the temporary PHPUnit configuration file.
     */
    protected function cleanupTemporaryConfigurationFile(): void
    {
        $temporaryConfigurationFile = $this->temporaryConfigurationFile;
        $this->temporaryConfigurationFile = null;

        if ($temporaryConfigurationFile !== null && is_file($temporaryConfigurationFile)) {
            unlink($temporaryConfigurationFile);
        }
    }

    /**
     * Report the slowest tests.
     */
    protected function reportProfile(): void
    {
        $directory = $this->profileDirectory();

        if (! is_dir($directory)) {
            return;
        }

        $tests = [];

        foreach (glob($directory . DIRECTORY_SEPARATOR . 'profile-*.json') ?: [] as $path) {
            /** @var array<int, array{name: string, duration: float}> $workerTests */
            $workerTests = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
            $tests = array_merge($tests, $workerTests);
        }

        if ($tests === []) {
            return;
        }

        usort(
            $tests,
            static fn (array $first, array $second): int => $second['duration'] <=> $first['duration'],
        );

        $this->newLine();
        $this->components->info('Top 10 slowest tests');

        foreach (array_slice($tests, 0, 10) as $test) {
            $this->line(sprintf('  %s <fg=yellow>%.3fs</>', $test['name'], $test['duration']));
        }
    }

    /**
     * Remove profile data.
     */
    protected function cleanupProfileDirectory(): void
    {
        $profileDirectory = $this->profileDirectory;
        $this->profileDirectory = null;

        if ($profileDirectory === null || ! is_dir($profileDirectory)) {
            return;
        }

        foreach (glob($profileDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($profileDirectory);
    }
}
