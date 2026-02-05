<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine\Channel;

use Hypervel\Coroutine\Channel\Manager as ChannelManager;
use Hypervel\Engine\Channel;
use Hypervel\Tests\Coroutine\CoroutineTestCase;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class ChannelManagerTest extends CoroutineTestCase
{
    public function testChannelManager(): void
    {
        $manager = new ChannelManager();
        $chan = $manager->get(1, true);
        $this->assertInstanceOf(Channel::class, $chan);
        $chan = $manager->get(1);
        $this->assertInstanceOf(Channel::class, $chan);
        go(function () use ($chan) {
            usleep(10 * 1000);
            $chan->push('Hello World.');
        });

        $this->assertSame('Hello World.', $chan->pop());
        $manager->close(1);
        $this->assertTrue($chan->isClosing());
        $this->assertNull($manager->get(1));
    }

    public function testChannelFlush(): void
    {
        $manager = new ChannelManager();
        $manager->get(1, true);
        $manager->get(2, true);
        $manager->get(4, true);
        $manager->get(5, true);

        $this->assertSame(4, count($manager->getChannels()));
        $manager->flush();
        $this->assertSame(0, count($manager->getChannels()));
    }
}
