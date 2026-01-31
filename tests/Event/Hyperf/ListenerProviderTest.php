<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event\Hyperf;

use Hypervel\Event\ListenerProvider;
use Hypervel\Tests\Event\Hyperf\Event\Alpha;
use Hypervel\Tests\Event\Hyperf\Event\Beta;
use Hypervel\Tests\Event\Hyperf\Listener\AlphaListener;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ListenerProviderTest extends TestCase
{
    public function testListenNotExistEvent(): void
    {
        $provider = new ListenerProvider();
        $provider->on(Alpha::class, [new AlphaListener(), 'process']);
        $provider->on('NotExistEvent', [new AlphaListener(), 'process']);

        $listeners = $provider->getListenersForEvent(new Alpha());
        $this->assertCount(1, $listeners);
        $listenerData = $listeners[0];
        [$class, $method] = $listenerData['listener'];
        $this->assertInstanceOf(AlphaListener::class, $class);
        $this->assertSame('process', $method);
        $this->assertFalse($listenerData['isWildcard']);

        $betaListeners = $provider->getListenersForEvent(new Beta());
        $this->assertEmpty($betaListeners);
    }
}
