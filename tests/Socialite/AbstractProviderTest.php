<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Tests\Socialite\Fixtures\GenericTestProviderStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use Swoole\Coroutine\Channel;

/**
 * Tests for the protocol-agnostic AbstractProvider base class.
 *
 * Verifies that config, HTTP client, state management, and context
 * isolation work independently of any protocol-specific subclass.
 */
class AbstractProviderTest extends TestCase
{
    public function testWithConfigSeedsBaselineConfig()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );
        $provider->withConfig([
            'base_url' => 'https://idp.example.com',
            'realm' => 'my-realm',
        ]);

        $this->assertSame('https://idp.example.com', $provider->getProviderConfig('base_url'));
        $this->assertSame('my-realm', $provider->getProviderConfig('realm'));
    }

    public function testSetConfigOverridesPerRequest()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );
        $provider->withConfig([
            'base_url' => 'https://idp.example.com',
            'realm' => 'default-realm',
        ]);

        $provider->setConfig(['realm' => 'tenant-realm']);

        $this->assertSame('https://idp.example.com', $provider->getProviderConfig('base_url'));
        $this->assertSame('tenant-realm', $provider->getProviderConfig('realm'));
    }

    public function testGetConfigReturnsDefaultForMissingKeys()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );

        $this->assertNull($provider->getProviderConfig('nonexistent'));
        $this->assertSame('fallback', $provider->getProviderConfig('nonexistent', 'fallback'));
    }

    public function testSetHttpClient()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );

        $client = m::mock(Client::class);
        $provider->setHttpClient($client);

        $this->assertSame($client, $provider->getProviderHttpClient());
    }

    public function testStatelessToggle()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );

        $this->assertTrue($provider->providerUsesState());

        $provider->stateless();

        $this->assertFalse($provider->providerUsesState());
    }

    public function testSetRequest()
    {
        $originalRequest = m::mock(Request::class);
        $newRequest = m::mock(Request::class);

        $provider = new GenericTestProviderStub($originalRequest);
        $provider->setRequest($newRequest);

        // Verify the request was updated by accessing the protected property via reflection
        $reflection = new ReflectionProperty($provider, 'request');
        $this->assertSame($newRequest, $reflection->getValue($provider));
    }

    public function testBaselineConfigSurvivesAcrossCoroutines()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );
        $provider->withConfig([
            'base_url' => 'https://idp.example.com',
        ]);

        $childValue = null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($provider, &$childValue, $channel) {
            $childValue = $provider->getProviderConfig('base_url');
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertSame('https://idp.example.com', $childValue);
    }

    public function testSetConfigIsIsolatedPerCoroutine()
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );
        $provider->withConfig([
            'realm' => 'default',
        ]);

        $provider->setConfig(['realm' => 'tenant_a']);

        $childRealm = null;
        $fallbackRealm = null;
        $channel = new Channel(2);

        Coroutine::create(function () use ($provider, &$childRealm, $channel) {
            $provider->setConfig(['realm' => 'tenant_b']);
            $childRealm = $provider->getProviderConfig('realm');
            $channel->push(true);
        });

        Coroutine::create(function () use ($provider, &$fallbackRealm, $channel) {
            $fallbackRealm = $provider->getProviderConfig('realm');
            $channel->push(true);
        });

        $channel->pop(1.0);
        $channel->pop(1.0);

        $parentRealm = $provider->getProviderConfig('realm');

        $this->assertSame('tenant_a', $parentRealm);
        $this->assertSame('tenant_b', $childRealm);
        $this->assertSame('default', $fallbackRealm);
    }
}
