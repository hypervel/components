<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack;

use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Response;
use Hypervel\Config\Repository;
use Hypervel\Notifications\Channels\SlackWebApiChannel;
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Tests\Notifications\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\Notifications\Slack\Fixtures\SlackChannelTestNotification;
use Hypervel\Tests\TestCase as BaseTestCase;
use Mockery as m;

abstract class TestCase extends BaseTestCase
{
    protected SlackWebApiChannel $slackChannel;

    protected HttpClient $client;

    protected Repository $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = m::mock(HttpClient::class);
        $this->config = new Repository([]);
        $this->slackChannel = new SlackWebApiChannel($this->client, $this->config);
    }

    protected function sendNotification(Closure $callback, ?string $routeChannel = '#ghost-talk'): static
    {
        $this->slackChannel->send(
            new SlackChannelTestNotifiable(new SlackRoute($routeChannel, 'fake-token')),
            new SlackChannelTestNotification($callback),
        );

        return $this;
    }

    protected function assertNotificationSent(
        array $payload,
        array $response = [],
        string $token = 'fake-token'
    ): void {
        $this->client->shouldReceive('post')
            ->once()
            ->with(
                'https://slack.com/api/chat.postMessage',
                [
                    'json' => $payload,
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                    ],
                ]
            )->andReturn(new Response(
                200,
                [],
                json_encode($response ?: ['ok' => true])
            ));
    }
}
