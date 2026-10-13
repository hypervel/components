<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Tests\TestCase;

class HookedStreamTest extends TestCase
{
    public function testSocketShutdownReportsTheNativeResult(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires hooked stream shutdown result parity from https://github.com/swoole/swoole-src/pull/6109.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $sender = stream_socket_client('tcp://' . $address);
        $receiver = stream_socket_accept($server, 1);

        try {
            $this->assertIsResource($sender);
            $this->assertIsResource($receiver);
            stream_set_timeout($receiver, 1);
            $this->assertSame(7, fwrite($sender, 'payload'));
            $this->assertTrue(stream_socket_shutdown($sender, STREAM_SHUT_WR));
            $this->assertSame('payload', stream_get_contents($receiver));
            $this->assertTrue(feof($receiver));
            $this->assertFalse(@stream_socket_shutdown($sender, STREAM_SHUT_WR));
        } finally {
            foreach ([$sender, $receiver, $server] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function testClosingAListenerImmediatelyReleasesItsAddress(): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Requires immediate hooked listener release from https://github.com/swoole/swoole-src/pull/6274.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        fclose($server);

        $this->assertFalse(@stream_socket_client('tcp://' . $address));

        $replacement = stream_socket_server('tcp://' . $address);

        try {
            $this->assertIsResource($replacement);
        } finally {
            if (is_resource($replacement)) {
                fclose($replacement);
            }
        }
    }
}
