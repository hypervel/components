<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request;
use Hypervel\Notifications\Slack\SlackChannel;
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Notifications\SlackChannelServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase as BaseTestCase;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotification;

abstract class TestCase extends BaseTestCase
{
    /**
     * The HTTP client factory.
     */
    protected Factory $http;

    /**
     * The Slack Web API channel.
     */
    protected SlackChannel $slackChannel;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SlackChannelServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->http = Http::fake(['slack.com/api/*' => Http::response(['ok' => true])]);
        $this->slackChannel = $this->app->make(SlackChannel::class);
    }

    /**
     * Send a notification built by the given callback.
     */
    protected function sendNotification(Closure $callback, ?string $routeChannel = '#ghost-talk'): static
    {
        $this->slackChannel->send(
            new SlackChannelTestNotifiable(new SlackRoute($routeChannel, 'fake-token')),
            new SlackChannelTestNotification($callback),
        );

        return $this;
    }

    /**
     * Assert the notification payload and authentication token.
     */
    protected function assertNotificationSent(array $payload, string $token = 'fake-token'): void
    {
        $this->http->assertSentCount(1);
        $this->http->assertSent(function (Request $request) use ($payload, $token): bool {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request->method() === 'POST'
                && $request->data() === $payload
                && $request->hasHeader('Authorization', "Bearer {$token}")
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }
}
