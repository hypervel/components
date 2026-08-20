<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

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
                'rate_limiting' => [
                    'enabled' => '1',
                    'max_attempts' => '120',
                    'decay_seconds' => '30',
                    'terminate_on_limit' => '0',
                ],
                'webhooks' => [
                    'subscription_count' => '1',
                    'disconnect_smoothing_ms' => '1500',
                    'timeout' => '10',
                    'retries' => '5',
                    'retry_delay' => '2',
                    'batching' => [
                        'enabled' => '1',
                        'max_events' => '100',
                        'max_delay_ms' => '500',
                        'max_payload_bytes' => '524288',
                    ],
                ],
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
            $application['max_connections'],
            $application['accept_client_events_from'],
            $application['options'],
            $application['rate_limiting'],
            $application['webhooks'],
        );

        $provider = new ConfigApplicationProvider(collect([$application]));
        $app = $provider->findByKey('reverb-key');

        $this->assertSame(Application::DEFAULT_ACTIVITY_TIMEOUT, $app->activityTimeout());
        $this->assertNull($app->maxConnections());
        $this->assertSame(Application::DEFAULT_ACCEPT_CLIENT_EVENTS_FROM, $app->acceptClientEventsFrom());
        $this->assertSame([], $app->options());
        $this->assertNull($app->rateLimiting());
        $this->assertFalse($app->usesRateLimiting());
        $this->assertSame([], $app->webhooks());
        $this->assertFalse($app->hasWebhooks());
    }

    public function testPartialRateLimitingAndWebhookRecordsUseTheirDefaults(): void
    {
        $application = config()->array('reverb.apps.apps.0');
        $application['rate_limiting'] = ['enabled' => '1'];
        $application['webhooks'] = ['url' => 'https://example.com/webhook'];

        $provider = new ConfigApplicationProvider(collect([$application]));
        $app = $provider->findByKey('reverb-key');

        $this->assertSame([
            'enabled' => true,
            'max_attempts' => 60,
            'decay_seconds' => 60,
            'terminate_on_limit' => false,
        ], $app->rateLimiting());
        $this->assertSame([
            'url' => 'https://example.com/webhook',
            'events' => [],
            'headers' => [],
            'filter' => [
                'channel_name_starts_with' => null,
                'channel_name_ends_with' => null,
            ],
            'subscription_count' => false,
            'disconnect_smoothing_ms' => 3000,
            'timeout' => 5,
            'retries' => 3,
            'retry_delay' => 1,
            'batching' => [
                'enabled' => false,
                'max_events' => 50,
                'max_delay_ms' => 250,
                'max_payload_bytes' => 262_144,
            ],
        ], $app->webhooks());
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
