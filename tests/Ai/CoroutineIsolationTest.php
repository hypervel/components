<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\Gateway\Gateway;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Config\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testOverlappingOnDemandProvidersKeepTheirCredentialsAndFlushLocalState(): void
    {
        $manager = $this->manager();
        $configured = $manager->instance();

        [$first, $second] = parallel([
            function () use ($manager): array {
                $provider = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'first-key']);
                usleep(5000);
                $this->assertSame($provider, $manager->instance('account'));
                $manager->flushState();

                return [$provider->providerCredentials(), WeakReference::create($provider)];
            },
            function () use ($manager): array {
                $provider = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'second-key']);
                usleep(10000);
                $this->assertSame($provider, $manager->instance('account'));

                return [$provider->providerCredentials(), WeakReference::create($provider)];
            },
        ]);

        $this->assertSame(['key' => 'first-key'], $first[0]);
        $this->assertSame(['key' => 'second-key'], $second[0]);
        $this->assertNull($first[1]->get());
        $this->assertNull($second[1]->get());
        $this->assertSame($configured, $manager->instance());
    }

    public function testProviderConfigFollowsOverlappingAndNestedAccountContexts(): void
    {
        $manager = $this->manager();
        $manager->resolveProviderConfigUsing(static fn (string $name, array $config): array => [
            ...$config,
            'key' => CoroutineContext::get('ai-test.account') . '-key',
        ]);

        [$first, $second] = parallel([
            function () use ($manager): array {
                CoroutineContext::set('ai-test.account', 'first');
                $provider = $manager->instance();
                usleep(5000);
                $this->assertSame(['key' => 'first-key'], $manager->instance()->providerCredentials());

                CoroutineContext::set('ai-test.account', 'nested');
                $this->assertSame(['key' => 'nested-key'], $manager->instance()->providerCredentials());

                return [$provider->providerCredentials(), WeakReference::create($provider)];
            },
            function () use ($manager): array {
                CoroutineContext::set('ai-test.account', 'second');
                usleep(5000);

                return $manager->instance()->providerCredentials();
            },
        ]);

        $this->assertSame(['key' => 'first-key'], $first[0]);
        $this->assertSame(['key' => 'second-key'], $second);
        $this->assertNull($first[1]->get());

        $explicit = $manager->build(['driver' => 'custom', 'key' => 'explicit-key']);
        $this->assertSame(['key' => 'explicit-key'], $explicit->providerCredentials());
    }

    public function testExplicitProviderObjectsKeepTheirIdentityAfterNamedProviderReplacement(): void
    {
        $manager = $this->manager();
        $first = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'first-key']);
        $second = $manager->build(['name' => 'account', 'driver' => 'custom', 'key' => 'second-key']);

        $this->assertSame($first, $manager->instance($first));
        $this->assertSame($second, $manager->instance('account'));

        $manager->forgetInstance('account');
        $rebuilt = $manager->instance('account');
        $this->assertNotSame($second, $rebuilt);
        $this->assertSame(['key' => 'second-key'], $rebuilt->providerCredentials());

        $manager->purge('account');
        $purged = $manager->instance('account');
        $this->assertNotSame($rebuilt, $purged);
        $this->assertSame(['key' => 'second-key'], $purged->providerCredentials());

        $this->assertSame(
            [[$first, null], [$second, null], ['openai', null], ['configured', 'fallback-model']],
            iterator_to_array(Provider::providerAndModelPairs([$first, $second, Lab::OpenAI, 'configured' => 'fallback-model'])),
        );
        $this->assertSame([['openai', 'model']], iterator_to_array(Provider::providerAndModelPairs(Lab::OpenAI, 'model')));
        $this->assertSame(['account' => null], Provider::formatProviderAndModelList($first));
    }

    /**
     * Create a manager with a custom provider requiring no external service.
     */
    protected function manager(): AiManager
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->with('config')->andReturn(new Repository([
            'ai' => [
                'default' => 'configured',
                'providers' => ['configured' => ['driver' => 'custom', 'key' => 'configured-key']],
            ],
        ]));
        $gateway = m::mock(Gateway::class);
        $events = m::mock(Dispatcher::class);
        $manager = new AiManager($application);
        $manager->extend('custom', static fn (Application $application, array $config): Provider => new IsolationTestProvider($gateway, $config, $events));

        return $manager;
    }
}

class IsolationTestProvider extends Provider
{
}
