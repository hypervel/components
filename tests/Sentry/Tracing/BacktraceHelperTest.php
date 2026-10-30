<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Tracing;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Sentry\Tracing\BacktraceHelper;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Sentry\Frame;
use Sentry\Options;
use Sentry\Serializer\RepresentationSerializer;

class BacktraceHelperTest extends TestCase
{
    public function testUnreadableCompiledViewReturnsNoOriginalPath(): void
    {
        $directory = ParallelTesting::tempDir('SentryBacktraceHelperTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($directory);
        $filesystem->makeDirectory($directory);
        $socketPath = "{$directory}/compiled-view.sock";
        $socket = stream_socket_server("unix://{$socketPath}");

        $this->assertIsResource($socket);

        try {
            Container::getInstance()->instance('config', new Repository(['view' => ['compiled' => $directory]]));
            $options = new Options(['dsn' => null]);
            $helper = new BacktraceHelper($options, new RepresentationSerializer($options));
            $frame = new Frame(null, '/storage/framework/views/compiled.php', 1, absoluteFilePath: $socketPath);

            $this->assertNull($helper->getOriginalViewPathForFrameOfCompiledViewPath($frame));
        } finally {
            fclose($socket);
            $filesystem->deleteDirectory($directory);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCustomCompiledViewPathResolvesOriginalSource(bool $symlinked): void
    {
        $directory = ParallelTesting::tempDir('SentryBacktraceHelperTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($directory);
        $filesystem->ensureDirectoryExists($directory . '/compiled');

        try {
            $compiledDirectory = $directory . '/compiled';

            if ($symlinked) {
                symlink($compiledDirectory, $directory . '/linked');
                $compiledDirectory = $directory . '/linked';
            }

            Container::getInstance()->instance('config', new Repository(['view' => ['compiled' => $compiledDirectory]]));
            $compiledPath = $directory . '/compiled/example.cache';
            $filesystem->put($compiledPath, '/**PATH ' . $directory . '/resources/views/example.blade.php ENDPATH**/');
            $options = new Options(['dsn' => null, 'prefixes' => [$directory]]);
            $helper = new BacktraceHelper($options, new RepresentationSerializer($options));
            $frame = new Frame(null, '/compiled/example.cache', 1, absoluteFilePath: $compiledPath);

            $this->assertSame('/resources/views/example.blade.php', $helper->getOriginalViewPathForFrameOfCompiledViewPath($frame));
        } finally {
            $filesystem->deleteDirectory($directory);
        }
    }
}
