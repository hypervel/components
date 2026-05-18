<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events;

use Hypervel\Events\CallQueuedListener as HypervelCallQueuedListener;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CallQueuedListenerTest extends TestCase
{
    public function testHypervelListenerToleratesUnknownPropertiesOnUnserialization()
    {
        $this->assertListenerToleratesUnknownProperties(
            HypervelCallQueuedListener::class
        );
    }

    /**
     * Simulates cross-version deserialization: a job payload serialized by a
     * newer Laravel/Hypervel version (which adds extra properties to
     * CallQueuedListener) is unserialized by an older version that does not
     * declare those properties. Without #[AllowDynamicProperties], PHP 8.2+
     * raises an error on the dynamic property assignment during unserialize().
     */
    private function assertListenerToleratesUnknownProperties(string $class): void
    {
        $listener = new $class('App\Listeners\OrderShipped', 'handle', []);
        $serialized = serialize($listener);

        // Inject a synthetic property absent from the current class definition.
        $extra = 's:18:"newPropertyFromV11";s:5:"value";';
        $serialized = preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+):/',
            fn ($m) => $m[1] . ((int) $m[2] + 1) . ':',
            $serialized
        );
        $serialized = substr($serialized, 0, -1) . $extra . '}';

        $result = unserialize($serialized);

        $this->assertInstanceOf($class, $result);
        $this->assertSame('App\Listeners\OrderShipped', $result->class);
        $this->assertSame('value', $result->newPropertyFromV11);
    }
}
