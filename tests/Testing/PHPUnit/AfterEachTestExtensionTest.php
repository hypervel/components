<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Symfony\Component\Process\Process;

class AfterEachTestExtensionTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    private ?string $tempDirectory = null;

    /**
     * Create an isolated directory for subprocess markers.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('AfterEachTestExtensionTest');
        $files = new Filesystem;

        $files->deleteDirectory($this->tempDirectory);
        $files->ensureDirectoryExists($this->tempDirectory);
    }

    /**
     * Remove subprocess markers.
     */
    protected function tearDown(): void
    {
        try {
            if ($this->tempDirectory !== null) {
                (new Filesystem)->deleteDirectory($this->tempDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testUnpreparedTestsAreCleanedBeforeTheNextTestStarts(): void
    {
        $marker = $this->tempDirectory . '/unprepared-cleanup.txt';
        $process = $this->runFixture(
            'UnpreparedCleanupFixture.php',
            ['HYPERVEL_UNPREPARED_CLEANUP_MARKER' => $marker],
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('intentional setup error', $process->getOutput());
        $this->assertStringContainsString('intentional data-provider setup error', $process->getOutput());
        $this->assertSame(
            ['error', 'skip', 'incomplete', 'data-provider'],
            file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    public function testFinalUnpreparedTestIsCleanedWhenTheRunnerFinishes(): void
    {
        $marker = $this->tempDirectory . '/final-cleanup.txt';
        $process = $this->runFixture(
            'FinalUnpreparedCleanupFixture.php',
            ['HYPERVEL_FINAL_CLEANUP_MARKER' => $marker],
        );

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('intentional final setup error', $process->getOutput());
        $this->assertSame(['cleaned'], file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /**
     * Run a PHPUnit fixture in a separate process with the real extension.
     *
     * @param array<string, string> $environment
     */
    private function runFixture(string $fixture, array $environment): Process
    {
        $process = new Process(
            command: [
                PHP_BINARY,
                'vendor/bin/phpunit',
                '--no-progress',
                'tests/Testing/PHPUnit/Fixtures/' . $fixture,
            ],
            cwd: dirname(__DIR__, 3),
            env: $environment,
            timeout: 30,
        );
        $process->run();

        return $process;
    }
}
