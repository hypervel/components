<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Tracing;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Sentry\Tracing\BacktraceHelper;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
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
            $options = new Options(['dsn' => null]);
            $helper = new BacktraceHelper($options, new RepresentationSerializer($options));
            $frame = new Frame(null, '/storage/framework/views/compiled.php', 1, absoluteFilePath: $socketPath);

            $this->assertNull($helper->getOriginalViewPathForFrameOfCompiledViewPath($frame));
        } finally {
            fclose($socket);
            $filesystem->deleteDirectory($directory);
        }
    }
}
