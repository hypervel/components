<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\ApplicationManager;
use Hypervel\Reverb\ConfigApplicationProvider;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Tests\Reverb\Fixtures\FakeApplicationProvider;

/**
 * @internal
 * @coversNothing
 */
class ApplicationProviderTest extends ReverbTestCase
{
    public function testRetrievesApplicationsFromCustomProvider()
    {
        $this->app->make(ApplicationManager::class)->extend('fake', fn () => new FakeApplicationProvider());

        config([
            'reverb.apps.provider' => 'fake',
            'reverb.apps.apps' => [],
        ]);

        $applicationsProvider = $this->app->make(ApplicationProvider::class);
        $application = $applicationsProvider->all()->first();

        $this->assertCount(1, $applicationsProvider->all());
        $this->assertInstanceOf(Application::class, $application);
        $this->assertSame('id', $application->toArray()['app_id']);
        $this->assertSame('key', $application->toArray()['key']);
        $this->assertSame('secret', $application->toArray()['secret']);
        $this->assertSame(60, $application->toArray()['ping_interval']);
        $this->assertSame(['*'], $application->toArray()['allowed_origins']);
        $this->assertSame(10_000, $application->toArray()['max_message_size']);
        $this->assertSame([
            'host' => 'localhost',
            'port' => 443,
            'scheme' => 'https',
            'useTLS' => true,
        ], $application->toArray()['options']);
    }

    public function testHandlesStringTypedConfigValuesFromEnv()
    {
        // env() returns strings — ConfigApplicationProvider must cast to correct types
        $provider = new ConfigApplicationProvider(collect([
            [
                'app_id' => '123456',
                'key' => 'reverb-key',
                'secret' => 'reverb-secret',
                'ping_interval' => '60',
                'activity_timeout' => '30',
                'allowed_origins' => ['*'],
                'max_message_size' => '10000',
                'max_connections' => '100',
                'accept_client_events_from' => 'members',
            ],
        ]));

        $app = $provider->findByKey('reverb-key');

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('123456', $app->id());
        $this->assertSame(60, $app->pingInterval());
        $this->assertSame(30, $app->activityTimeout());
        $this->assertSame(10000, $app->maxMessageSize());
        $this->assertSame(100, $app->maxConnections());
    }
}
