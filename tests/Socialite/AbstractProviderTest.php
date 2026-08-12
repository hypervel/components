<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite;

use GuzzleHttp\Client;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Tests\Socialite\Fixtures\GenericTestProviderStub;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Swoole\Coroutine\Channel;
use Throwable;

use function Hypervel\Coroutine\parallel;

/**
 * Tests for the protocol-agnostic AbstractProvider base class.
 *
 * Verifies that config, HTTP client, state management, and context
 * isolation work independently of any protocol-specific subclass.
 */
class AbstractProviderTest extends TestCase
{
    public function testWithConfigSeedsBaselineConfig(): void
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

    public function testSetConfigOverridesPerRequest(): void
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

    public function testGetConfigReturnsDefaultForMissingKeys(): void
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );

        $this->assertNull($provider->getProviderConfig('nonexistent'));
        $this->assertSame('fallback', $provider->getProviderConfig('nonexistent', 'fallback'));
    }

    public function testGetConfigDelegatesNullAndZeroKeysToArr(): void
    {
        $provider = new GenericTestProviderStub(m::mock(Request::class));
        $provider->withConfig([
            0 => 'zero',
            'realm' => 'default',
        ]);

        $this->assertSame('zero', $provider->getProviderConfig('0'));
        $this->assertSame([0 => 'zero', 'realm' => 'default'], $provider->getProviderConfig());
    }

    public function testSetHttpClient(): void
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );

        $client = m::mock(Client::class);
        $provider->setHttpClient($client);

        $this->assertSame($client, $provider->getProviderHttpClient());
    }

    public function testStatelessToggle(): void
    {
        $provider = new GenericTestProviderStub(
            m::mock(Request::class),
        );

        $this->assertTrue($provider->providerUsesState());

        $provider->stateless();

        $this->assertFalse($provider->providerUsesState());
    }

    public function testSetRequest(): void
    {
        $originalRequest = m::mock(Request::class);
        $newRequest = m::mock(Request::class);

        $provider = new GenericTestProviderStub($originalRequest);
        $provider->setRequest($newRequest);

        $this->assertSame($newRequest, $provider->getProviderRequest());
    }

    public function testSetRequestIsIsolatedPerCoroutine(): void
    {
        $provider = new GenericTestProviderStub(
            Request::create('/baseline'),
        );

        [$pathA, $pathB] = parallel([
            function () use ($provider): string {
                $provider->setRequest(Request::create('/tenant-a'));

                usleep(5000);

                return $provider->getProviderRequest()->getPathInfo();
            },
            function () use ($provider): string {
                usleep(2500);

                $provider->setRequest(Request::create('/tenant-b'));

                return $provider->getProviderRequest()->getPathInfo();
            },
        ]);

        $this->assertSame('/tenant-a', $pathA);
        $this->assertSame('/tenant-b', $pathB);
    }

    public function testRequestMustBeSeededInEachCoroutine(): void
    {
        $provider = new GenericTestProviderStub(Request::create('/baseline'));
        $channel = new Channel(1);

        Coroutine::create(function () use ($provider, $channel): void {
            try {
                $provider->getProviderRequest();
            } catch (Throwable $exception) {
                $channel->push($exception);
            }
        });

        $exception = $channel->pop(1.0);

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertSame(
            'No request is available for this provider. Resolve it through Socialite::driver() or call setRequest().',
            $exception->getMessage()
        );
    }

    public function testRecycledObjectIdsCannotReuseProviderContext(): void
    {
        $request = Request::create('/');
        $provider = new GenericTestProviderStub($request);
        $objectId = spl_object_id($provider);
        $provider->rememberProviderMarker('tenant-a');

        unset($provider);

        $replacement = null;

        for ($attempt = 0; $attempt < 1000; ++$attempt) {
            $candidate = new GenericTestProviderStub($request);

            if (spl_object_id($candidate) === $objectId) {
                $replacement = $candidate;
                break;
            }

            unset($candidate);
        }

        $this->assertNotNull($replacement);
        $this->assertNull($replacement->getProviderMarker());
    }

    public function testBaselineConfigSurvivesAcrossCoroutines(): void
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

    public function testSetConfigIsIsolatedPerCoroutine(): void
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
