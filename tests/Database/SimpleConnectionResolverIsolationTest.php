<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\SimpleConnectionResolver;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

/**
 * Regression guard: SimpleConnectionResolver must keep its default connection
 * in an instance-local property rather than in CoroutineContext.
 *
 * Why this matters: outside a coroutine, CoroutineContext falls back to a
 * static $nonCoroutineContext map (see CoroutineContext.php). Routing
 * SimpleConnectionResolver's default through that map would collapse the
 * instance-level isolation that Capsule and test harnesses rely on — two
 * separate resolvers would share the same default override slot.
 *
 * This test exists purely to break loudly if someone "cleans up" the two
 * resolver implementations by making them use the same Context mechanism.
 */
class SimpleConnectionResolverIsolationTest extends TestCase
{
    use VerifiesDoubles;

    public function testTwoInstancesHaveIndependentDefaults(): void
    {
        $resolverA = new SimpleConnectionResolver(Double::for(DatabaseManager::class)->strict());
        $resolverB = new SimpleConnectionResolver(Double::for(DatabaseManager::class)->strict());

        $resolverA->setDefaultConnection('alpha');
        $resolverB->setDefaultConnection('beta');

        $this->assertSame('alpha', $resolverA->getDefaultConnection());
        $this->assertSame('beta', $resolverB->getDefaultConnection());
    }

    public function testSetDefaultConnectionDoesNotWriteToSharedContext(): void
    {
        $resolver = new SimpleConnectionResolver(Double::for(DatabaseManager::class)->strict());

        // Pre-set Context to a sentinel — SimpleConnectionResolver must NOT
        // touch this key, otherwise its setter is leaking into the pooled
        // ConnectionResolver's state.
        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'sentinel');

        $resolver->setDefaultConnection('something');

        $this->assertSame(
            'sentinel',
            CoroutineContext::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY),
            'SimpleConnectionResolver must not mutate the Context key owned by ConnectionResolver',
        );
    }
}
