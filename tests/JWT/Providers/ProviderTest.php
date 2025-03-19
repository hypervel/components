<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Providers;

use Hypervel\Tests\JWT\Stub\ProviderStub;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ProviderTest extends TestCase
{
    protected $provider;

    public function testSetTheAlgo()
    {
        $provider = new ProviderStub('secret', 'HS256', []);

        $provider->setAlgo('HS512');

        $this->assertSame('HS512', $provider->getAlgo());
    }

    public function testSetTheSecret()
    {
        $provider = new ProviderStub('secret', 'HS256', []);

        $provider->setSecret('foo');

        $this->assertSame('foo', $provider->getSecret());
    }
}
