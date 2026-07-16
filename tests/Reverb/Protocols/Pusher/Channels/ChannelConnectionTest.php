<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\TestCase;

class ChannelConnectionTest extends TestCase
{
    public function testZeroDataKeyIsPreserved(): void
    {
        $connection = new ChannelConnection(new FakeConnection, [
            '0' => 'zero',
            'name' => 'Taylor',
        ]);

        $this->assertSame('zero', $connection->data('0'));
        $this->assertSame([
            0 => 'zero',
            'name' => 'Taylor',
        ], $connection->data());
    }
}
