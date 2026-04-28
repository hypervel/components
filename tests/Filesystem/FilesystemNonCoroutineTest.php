<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use Symfony\Component\Process\Process;

/**
 * Tests for cross-process file locking behavior.
 */
class FilesystemNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? 'default';
        $this->tempDir = sys_get_temp_dir() . '/hypervel-fs-' . $token . '-' . getmypid() . '-NonCoroutineTest';

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSharedGet()
    {
        $content = str_repeat('123456', 1000000);
        $filePath = $this->tempDir . '/file.txt';

        // Pre-create the file so all subprocesses operate on an existing file
        file_put_contents($filePath, $content);

        $script = <<<'PHP'
        <?php
        require_once $argv[1];

        $files = new \Hypervel\Filesystem\Filesystem();
        $path = $argv[2];
        $expectedLength = (int) $argv[3];
        $content = str_repeat('123456', 1000000);

        $files->put($path, $content, true);
        $read = $files->get($path, true);

        exit(strlen($read) === $expectedLength ? 0 : 1);
        PHP;

        $scriptPath = $this->tempDir . '/worker.php';
        file_put_contents($scriptPath, $script);

        $autoloader = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $processes = [];

        for ($i = 0; $i < 20; ++$i) {
            $process = new Process([PHP_BINARY, $scriptPath, $autoloader, $filePath, (string) strlen($content)]);
            $process->start();
            $processes[] = $process;
        }

        $allSucceeded = true;
        foreach ($processes as $index => $process) {
            $process->wait();
            if ($process->getExitCode() !== 0) {
                $allSucceeded = false;
            }
        }

        $this->assertTrue($allSucceeded, 'At least one subprocess got a partial or corrupt read');
    }

    public function testLockedPutWithStreamResource()
    {
        $files = new Filesystem;
        $path = $this->tempDir . '/stream.txt';

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'stream content here');
        rewind($stream);

        $files->put($path, $stream, true);
        fclose($stream);

        $this->assertStringEqualsFile($path, 'stream content here');
    }

    public function testLockedPutTruncatesLongerExistingContent()
    {
        $files = new Filesystem;
        $path = $this->tempDir . '/truncate.txt';

        $files->put($path, 'this is longer content that should be gone', true);
        $files->put($path, 'short', true);

        $this->assertSame('short', file_get_contents($path));
    }
}
