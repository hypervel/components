<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Providers;

use Hypervel\Tests\Jwt\Fixtures\ProviderStub;
use Hypervel\Tests\TestCase;

class ProviderTest extends TestCase
{
    public function testSetTheAlgo(): void
    {
        $provider = new ProviderStub('secret', 'HS256', []);

        $provider->setAlgo('HS512');

        $this->assertSame('HS512', $provider->getAlgo());
    }

    public function testSetTheSecret(): void
    {
        $provider = new ProviderStub('secret', 'HS256', []);

        $provider->setSecret('foo');

        $this->assertSame('foo', $provider->getSecret());
    }

    public function testSetTheKeys(): void
    {
        $provider = new ProviderStub('secret', 'HS256', []);

        $provider->setKeys($keys = ['private' => 'priv', 'public' => 'pub']);

        $this->assertSame($keys, $provider->getKeys());
    }
}
