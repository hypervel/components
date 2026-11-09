<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Symfony\Component\Process\Process;

use function basename;
use function class_basename;
use function dirname;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function sort;

/**
 * Analyses a copy of a fixture application before and after an edit, to check that the edit
 * reanalyses the files depending on it on a warm result cache.
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
     * and the opt-in rules the fixtures exercise on, plus any extra top-level configuration.
     *
     * PHPStan uses its result cache only when it analyses a directory, and reports whether it reused
     * the cache on standard error at the very verbose level. The application's autoloader is the
     * autoload file, so classes PHPStan registers as services load before it builds its container.
     */
    protected function analyse(string $applicationPath, string $configuration = ''): Process
    {
        $repositoryPath = dirname(__DIR__, 2);

        file_put_contents($applicationPath . '/phpstan.neon', <<<NEON
            includes:
                - {$repositoryPath}/src/phpstan/extension.neon
            parameters:
                level: 5
                tmpDir: {$applicationPath}/cache
                enableMigrationCache: true
                checkBatchableJobChecksCancellation: true
                paths:
                    - {$applicationPath}/app
            {$configuration}
            NEON);

        $process = new Process(
            [PHP_BINARY, $repositoryPath . '/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', "--autoload-file={$applicationPath}/bootstrap/autoload.php", '--no-progress', '--error-format=json', '--no-ansi', '-vv'],
            $applicationPath,
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
}
