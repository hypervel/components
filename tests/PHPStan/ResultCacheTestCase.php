<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Symfony\Component\Process\Process;

use function basename;
use function class_basename;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function sort;
use function str_replace;

/**
 * Analyses a copy of a fixture application in its own PHPStan process, such as before and after
 * an edit to check that the edit reanalyses the files depending on it on a warm result cache.
 */
abstract class ResultCacheTestCase extends TestCase
{
    /**
     * The fixture application's directory under Fixtures.
     */
    protected string $fixture;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('PHPStan' . class_basename(static::class));
        (new Filesystem)->deleteDirectory($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * Copy the fixture application to the temporary directory.
     */
    protected function copyApplication(): string
    {
        $applicationPath = $this->tempDir . '/application';
        (new Filesystem)->copyDirectory(__DIR__ . "/Fixtures/{$this->fixture}/application", $applicationPath);
        mkdir($applicationPath . '/bootstrap/cache');

        return $applicationPath;
    }

    /**
     * Run PHPStan from the application over its app directory, with the result and migration caches
     * and the opt-in rules the fixtures exercise on, plus any extra top-level configuration and parameters.
     *
     * PHPStan uses its result cache only when it analyses a directory, and reports whether it reused
     * the cache on standard error at the very verbose level. The application's autoloader is the
     * autoload file, so classes PHPStan registers as services load before it builds its container.
     * Reusing collected-data rule errors is the bleeding-edge toggle that skips those rules on a warm
     * run while their collected data and tracked dependencies are unchanged.
     *
     * @param array<string, false|string> $environment variables to set, or to unset with false, over the test's own
     */
    protected function analyse(
        string $applicationPath,
        string $configuration = '',
        bool $reuseCollectedDataRuleErrors = false,
        array $environment = [],
        string $parameters = '',
    ): Process {
        $repositoryPath = dirname(__DIR__, 2);
        $reuse = $reuseCollectedDataRuleErrors ? 'true' : 'false';
        $parameters = str_replace("\n", "\n    ", $parameters);

        file_put_contents($applicationPath . '/phpstan.neon', <<<NEON
            includes:
                - {$repositoryPath}/src/phpstan/extension.neon
            parameters:
                level: 5
                tmpDir: {$applicationPath}/cache
                enableMigrationCache: true
                checkBatchableJobChecksCancellation: true
                featureToggles:
                    reuseCollectedDataRuleErrors: {$reuse}
                paths:
                    - {$applicationPath}/app
                {$parameters}
            {$configuration}
            NEON);

        $process = new Process(
            [PHP_BINARY, $repositoryPath . '/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', "--autoload-file={$applicationPath}/bootstrap/autoload.php", '--no-progress', '--error-format=json', '--no-ansi', '-vv'],
            $applicationPath,
            $environment,
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
    protected function diagnostics(Process $process, int $exitCode): array
    {
        $this->assertSame($exitCode, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());

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
     * Replace text that must be in the file.
     */
    protected function edit(string $path, string $search, string $replace): void
    {
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString($search, $contents);

        file_put_contents($path, str_replace($search, $replace, $contents));
    }

    /**
     * Assert how many files a warm run reanalysed.
     */
    protected function assertReanalysed(int $count, Process $process): void
    {
        $this->assertStringContainsString(
            "Result cache restored. {$count} " . ($count === 1 ? 'file' : 'files') . ' will be reanalysed.',
            $process->getErrorOutput(),
        );
    }
}
