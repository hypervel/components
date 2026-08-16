<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use ErrorException;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\ApplicationManager;
use Hypervel\Reverb\ConfigApplicationProvider;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Tests\Reverb\Fixtures\FakeApplicationProvider;

class ApplicationProviderTest extends ReverbTestCase
{
    public function testRetrievesApplicationsFromCustomProvider(): void
    {
        $this->app->make(ApplicationManager::class)->extend('fake', fn () => new FakeApplicationProvider);

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

    public function testNormalizesNumericApplicationConfigValues(): void
    {
        $application = config()->array('reverb.apps.apps.0');
        $provider = new ConfigApplicationProvider(collect([
            array_replace($application, [
                'app_id' => '123456',
                'key' => 'reverb-key',
                'secret' => 'reverb-secret',
                'ping_interval' => '60',
                'activity_timeout' => '45',
                'allowed_origins' => ['*'],
                'max_message_size' => '10000',
                'max_connections' => '100',
                'accept_client_events_from' => 'members',
                'rate_limiting' => array_replace($application['rate_limiting'], [
                    'enabled' => '1',
                    'max_attempts' => '120',
                    'decay_seconds' => '30',
                    'terminate_on_limit' => '0',
                ]),
                'webhooks' => array_replace($application['webhooks'], [
                    'subscription_count' => '1',
                    'disconnect_smoothing_ms' => '1500',
                    'timeout' => '10',
                    'retries' => '5',
                    'retry_delay' => '2',
                    'batching' => array_replace($application['webhooks']['batching'], [
                        'enabled' => '1',
                        'max_events' => '100',
                        'max_delay_ms' => '500',
                        'max_payload_bytes' => '524288',
                    ]),
                ]),
            ]),
        ]));

        $app = $provider->findByKey('reverb-key');

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('123456', $app->id());
        $this->assertSame(60, $app->pingInterval());
        $this->assertSame(45, $app->activityTimeout());
        $this->assertSame(10000, $app->maxMessageSize());
        $this->assertSame(100, $app->maxConnections());
        $this->assertTrue($app->usesRateLimiting());
        $this->assertSame([
            'enabled' => true,
            'max_attempts' => 120,
            'decay_seconds' => 30,
            'terminate_on_limit' => false,
        ], $app->rateLimiting());
        $this->assertSame(1500, $app->webhooks()['disconnect_smoothing_ms']);
        $this->assertSame(10, $app->webhooks()['timeout']);
        $this->assertSame(5, $app->webhooks()['retries']);
        $this->assertSame(2, $app->webhooks()['retry_delay']);
        $this->assertTrue($app->webhooks()['subscription_count']);
        $this->assertSame([
            'enabled' => true,
            'max_events' => 100,
            'max_delay_ms' => 500,
            'max_payload_bytes' => 524288,
        ], $app->webhooks()['batching']);
    }

    public function testOptionalApplicationMembersUseTheirDefaultsWhenOmitted(): void
    {
        $application = config()->array('reverb.apps.apps.0');
        unset(
            $application['activity_timeout'],
            $application['options'],
            $application['rate_limiting'],
            $application['webhooks'],
        );

        $provider = new ConfigApplicationProvider(collect([$application]));
        $app = $provider->findByKey('reverb-key');

        $this->assertSame(Application::DEFAULT_ACTIVITY_TIMEOUT, $app->activityTimeout());
        $this->assertSame([], $app->options());
        $this->assertNull($app->rateLimiting());
        $this->assertFalse($app->usesRateLimiting());
        $this->assertSame([], $app->webhooks());
        $this->assertFalse($app->hasWebhooks());
    }

    public function testMissingAcceptClientEventsFromFailsLoudly(): void
    {
        $application = config()->array('reverb.apps.apps.0');
        unset($application['accept_client_events_from']);

        $provider = new ConfigApplicationProvider(collect([$application]));

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "accept_client_events_from"');

        $provider->findByKey('reverb-key');
    }

    public function testNullAndBlankWebhookUrlsDisableWebhooks(): void
    {
        foreach ([null, ''] as $url) {
            $application = config()->array('reverb.apps.apps.0');
            $application['webhooks']['url'] = $url;

            $provider = new ConfigApplicationProvider(collect([$application]));

            $this->assertFalse($provider->findByKey('reverb-key')->hasWebhooks());
        }
    }
}
