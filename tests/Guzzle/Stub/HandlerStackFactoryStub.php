<?php

declare(strict_types=1);

namespace Hypervel\Tests\Guzzle\Stub;

use Hypervel\Guzzle\HandlerStackFactory;

/**
 * Test stub that disables pool handler for simpler testing.
 *
 * Forces the factory to use CoroutineHandler instead of PoolHandler,
 * useful for testing handler stack creation without pool dependencies.
 */
class HandlerStackFactoryStub extends HandlerStackFactory
{
    public function __construct()
    {
        $this->usePoolHandler = false;
    }
}
