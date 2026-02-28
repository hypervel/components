<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hypervel\Event\ListenerProvider;
use Hypervel\Tests\Event\Stub\Alpha;
use Hypervel\Tests\Event\Stub\AlphaListener;
use Hypervel\Tests\Event\Stub\Beta;
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
