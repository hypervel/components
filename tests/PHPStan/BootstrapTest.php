<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\PHPStan\Fixtures\Bootstrap\BootstrapFixtureServiceProvider;
use Symfony\Component\Process\Process;

class BootstrapTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('PHPStanBootstrapTest');
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testPackageAnalysisBootsTestbenchInEveryProcess(): void
    {
        $packagePath = $this->tempDir . '/package';
        mkdir($packagePath);
        file_put_contents($packagePath . '/testbench.yaml', 'env: []');
        // The provider is declared only in the package's own Composer metadata, as an
        // installing application would discover it.
        file_put_contents($packagePath . '/composer.json', json_encode([
            'name' => 'hypervel-phpstan/bootstrap-fixture',
            'extra' => ['hypervel' => ['providers' => [BootstrapFixtureServiceProvider::class]]],
        ], JSON_THROW_ON_ERROR));

        $process = $this->analyse($packagePath, ['AspectGreeter.php', 'ReplacedGreeter.php', 'Usage.php']);

        // Aspect targets report against their original source, and the class-map
        // replacement supplies replacementOnly().
        $this->assertSame([
            'AspectGreeter.php:22 return.type',
            'Usage.php:14 argument.type',
        ], $this->diagnostics($process));

        $evidence = $this->evidence();

        $this->assertGreaterThanOrEqual(2, count($evidence));
        $this->assertCount(count($evidence), array_unique(array_column($evidence, 'pid')));

        foreach ($evidence as $boot) {
            $this->assertTrue($boot['booted']);
            $this->assertTrue($boot['macro']);
            $this->assertStringContainsString('/hypervel-components-testbench-', $boot['basePath']);
            $this->assertDirectoryDoesNotExist($boot['basePath']);
        }
    }

    public function testComponentsAnalysisBootsItsOwnProviders(): void
    {
        $repositoryPath = dirname(__DIR__, 2);

        // Testbench's contract-suite configuration, which Testbench selects for the components repo, excludes them.
        $process = $this->runBootstrap($repositoryPath, code: <<<'PHP'
            echo json_encode([
                Hypervel\Testbench\Bootstrapper::getConfiguration()?->getConfigurationPath(),
                isset(Hypervel\Support\Facades\Facade::getFacadeApplication()->getLoadedProviders()[Hypervel\Telescope\TelescopeServiceProvider::class]),
            ]);
            PHP);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([$repositoryPath, true], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testApplicationAnalysisBootsTheApplication(): void
    {
        $applicationPath = $this->tempDir . '/application';
        (new Filesystem)->copyDirectory(__DIR__ . '/Fixtures/Bootstrap/application', $applicationPath);
        mkdir($applicationPath . '/bootstrap/cache');

        $process = $this->analyse($applicationPath, ['Usage.php']);

        $this->assertSame(['Usage.php:14 argument.type'], $this->diagnostics($process));

        foreach ($this->evidence() as $boot) {
            $this->assertTrue($boot['booted']);
            $this->assertTrue($boot['macro']);
            $this->assertSame($applicationPath, $boot['basePath']);
        }
    }

    public function testInvalidApplicationBootstrapFails(): void
    {
        $applicationPath = $this->tempDir . '/application';
        mkdir($applicationPath . '/bootstrap', 0777, true);
        file_put_contents($applicationPath . '/bootstrap/app.php', '<?php return null;');

        $process = $this->runBootstrap($applicationPath);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            "The application bootstrap file [{$applicationPath}/bootstrap/app.php] must return the Hypervel application.",
            $process->getErrorOutput(),
        );
    }

    public function testBootFailureReportsTheRuntimeRequirement(): void
    {
        $packagePath = $this->tempDir . '/package';
        mkdir($packagePath);

        $process = $this->runBootstrap($packagePath, ['-d', 'swoole.use_shortname=On']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'Swoole short function names must be disabled. Set swoole.use_shortname=Off in your php.ini.',
            $process->getErrorOutput(),
        );
    }

    /**
     * Run the analyzer's bootstrap file from the working path.
     *
     * PHPStan only requires this file, so its boot needs no analysis run or container
     * compilation. The code runs after it.
     *
     * @param list<string> $phpOptions
     */
    protected function runBootstrap(string $workingPath, array $phpOptions = [], string $code = ''): Process
    {
        $repositoryPath = dirname(__DIR__, 2);

        $process = new Process(
            [PHP_BINARY, ...$phpOptions, '-r', 'require $argv[1]; require $argv[2]; ' . $code, $repositoryPath . '/vendor/autoload.php', $repositoryPath . '/src/phpstan/bootstrap.php'],
            $workingPath,
            timeout: 60,
        );
        $process->run();

        return $process;
    }

    /**
     * Run PHPStan from the working path over the given bootstrap fixtures.
     *
     * @param list<string> $files
     */
    protected function analyse(string $workingPath, array $files): Process
    {
        $repositoryPath = dirname(__DIR__, 2);
        $fixturesPath = __DIR__ . '/Fixtures/Bootstrap';
        $paths = implode('', array_map(
            static fn (string $file): string => "\n        - {$fixturesPath}/{$file}",
            $files,
        ));

        file_put_contents($workingPath . '/phpstan.neon', <<<NEON
            includes:
                - {$repositoryPath}/src/phpstan/extension.neon
            parameters:
                level: 5
                tmpDir: {$workingPath}/cache
                paths:{$paths}
                bootstrapFiles:
                    - {$fixturesPath}/evidence.php
                parallel:
                    jobSize: 1
                    maximumNumberOfProcesses: 2
                    minimumNumberOfJobsPerProcess: 1
            NEON);

        $process = new Process(
            [PHP_BINARY, $repositoryPath . '/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', '--no-progress', '--error-format=json', '--no-ansi'],
            $workingPath,
            ['HYPERVEL_PHPSTAN_BOOT_EVIDENCE' => $this->tempDir . '/evidence.jsonl'],
            timeout: 300,
        );
        $process->run();

        return $process;
    }

    /**
     * Get the file diagnostics as "file:line identifier" entries.
     *
     * @return list<string>
     */
    protected function diagnostics(Process $process): array
    {
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $result['errors']);

        $diagnostics = [];

        foreach ($result['files'] as $file => $errors) {
            foreach ($errors['messages'] as $message) {
                $diagnostics[] = basename($file) . ":{$message['line']} {$message['identifier']}";
            }
        }

        sort($diagnostics);

        return $diagnostics;
    }

    /**
     * Get the boot evidence recorded by each PHPStan process.
     *
     * @return list<array{pid: int, application: bool, basePath: null|string, booted: bool, macro: bool}>
     */
    protected function evidence(): array
    {
        $lines = file($this->tempDir . '/evidence.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertNotEmpty($lines);

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
