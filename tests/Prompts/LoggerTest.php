<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Support\Logger;
use Hypervel\Tests\TestCase;
use ReflectionProperty;

class LoggerTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testWritesCompleteProtocolFrames(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $logger = new Logger('abc123', $sockets[0]);

        try {
            $logger->line('plain');
            $logger->success('done');

            $this->assertSame("plain\n", fgets($sockets[1]));
            $this->assertSame("abc123_success:done\n", fgets($sockets[1]));
            $this->assertNull($logger->transportFailure());
        } finally {
            fclose($sockets[0]);
            fclose($sockets[1]);
        }
    }

    public function testWritesPayloadLargerThanSocketBufferWhileReaderDrains(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $payload = str_repeat('x', 2 * 1024 * 1024);
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($sockets[1]);
            $logger = new Logger('abc123', $sockets[0]);
            $logger->line($payload);
            fclose($sockets[0]);

            exit($logger->transportFailure() === null ? 0 : 1);
        }

        $this->assertGreaterThan(0, $pid);
        fclose($sockets[0]);
        $received = stream_get_contents($sockets[1]);
        fclose($sockets[1]);
        pcntl_waitpid($pid, $status);

        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame($payload . PHP_EOL, $received);
    }

    public function testPeerClosureLatchesFailureAndStopsLaterWrites(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $logger = new Logger('abc123', $sockets[0]);
        $streamBuffer = new ReflectionProperty($logger, 'streamBuffer');
        $firstChunk = str_repeat('x', 1024 * 1024);
        fclose($sockets[1]);

        try {
            $logger->partial($firstChunk);
            $failure = $logger->transportFailure();

            $this->assertNotNull($failure);
            $this->assertSame($firstChunk, $streamBuffer->getValue($logger));

            $logger->line('ignored');
            $logger->partial(' ignored');

            $this->assertSame($failure, $logger->transportFailure());
            $this->assertSame($firstChunk, $streamBuffer->getValue($logger));
        } finally {
            fclose($sockets[0]);
        }
    }

    public function testNoReaderTimesOutAfterOneWindowFollowingAPartialWrite(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($sockets[0], 1);
        $logger = new Logger('abc123', $sockets[0]);
        $payload = str_repeat('x', 8 * 1024 * 1024);
        $startedAt = hrtime(true);

        try {
            $logger->line($payload);
            $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

            $this->assertSame(
                'The prompt renderer timed out while receiving output.',
                $logger->transportFailure()?->getMessage(),
            );
            $this->assertLessThan(2.0, $elapsed);

            stream_set_blocking($sockets[1], false);
            $received = stream_get_contents($sockets[1]);

            $this->assertNotSame('', $received);
            $this->assertLessThan(strlen($payload), strlen($received));
        } finally {
            fclose($sockets[0]);
            fclose($sockets[1]);
        }
    }

    public function testProgressingReaderMayExceedTheNoProgressWindow(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($sockets[0], 1);
        $payload = str_repeat('x', 2 * 1024 * 1024);
        $pid = pcntl_fork();

        if ($pid === 0) {
            fclose($sockets[0]);
            $received = '';

            while (! feof($sockets[1])) {
                usleep(5_000);
                $chunk = fread($sockets[1], 8192);

                if ($chunk !== false) {
                    $received .= $chunk;
                }
            }

            fclose($sockets[1]);

            exit($received === $payload . PHP_EOL ? 0 : 1);
        }

        $this->assertGreaterThan(0, $pid);
        fclose($sockets[1]);
        $logger = new Logger('abc123', $sockets[0]);
        $startedAt = hrtime(true);
        $logger->line($payload);
        fclose($sockets[0]);
        pcntl_waitpid($pid, $status);
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertGreaterThan(1.0, $elapsed);
        $this->assertNull($logger->transportFailure());
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function testDoesNotThrowWhenConstructedWithoutSocket(): void
    {
        $logger = new Logger('abc123');

        $logger->line('hello');
        $logger->partial('streamed ');
        $logger->commitPartial();
        $logger->success('done');
        $logger->warning('careful');
        $logger->error('broken');
        $logger->label('Updated');
        $logger->subLabel('detail');

        $streamBuffer = new ReflectionProperty($logger, 'streamBuffer');

        $this->assertSame('', $streamBuffer->getValue($logger));
    }
}
