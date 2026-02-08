<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use GuzzleHttp\Client as HttpClient;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Notifications\Slack\SlackRoute;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class SlackWebApiChannel
{
    protected const SLACK_API_URL = 'https://slack.com/api/chat.postMessage';

    /**
     * Create a new Slack channel instance.
     */
    public function __construct(
        protected HttpClient $client,
        protected Repository $config
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?ResponseInterface
    {
        if (! method_exists($notification, 'toSlack')) {
            throw new RuntimeException('Notification is missing `toSlack` method.');
        }

        // @phpstan-ignore-next-line
        $message = $notification->toSlack($notifiable);

        $route = $this->determineRoute($notifiable, $notification);

        $payload = $this->buildJsonPayload($message, $route);

        if (! $payload['channel']) {
            throw new LogicException('Slack notification channel is not set.');
        }

        if (! $route->token) {
            throw new LogicException('Slack API authentication token is not set.');
        }

        $response = $this->client->post(static::SLACK_API_URL, [
            'json' => $payload,
            'headers' => [
                'Authorization' => "Bearer {$route->token}",
            ],
        ]);

        $result = json_decode($content = $response->getBody()->getContents(), true);
        if ($response->getStatusCode() === 200 && ($result['ok'] ?? false) === false) {
            throw new RuntimeException('Slack API call failed with error [' . ($result['error'] ?? $content) . '].');
        }

        return $response;
    }

    /**
     * Build the JSON payload for the Slack chat.postMessage API.
     */
    protected function buildJsonPayload(SlackMessage $message, SlackRoute $route): array
    {
        $payload = $message->toArray();

        return array_merge($payload, [
            'channel' => $route->channel ?? $payload['channel'] ?? $this->config->get('services.slack.notifications.channel'),
        ]);
    }

    /**
     * Determine the API Token and Channel that the notification should be posted to.
     */
    protected function determineRoute(mixed $notifiable, Notification $notification): SlackRoute
    {
        $route = $notifiable->routeNotificationFor('slack', $notification);

        // When the route is a string, we will assume it is a channel name and will use the default API token for the application...
        if (is_string($route)) {
            return SlackRoute::make($route, $this->config->get('services.slack.notifications.bot_user_oauth_token'));
        }

        return SlackRoute::make(
            $route->channel ?? null,
            $route->token ?? $this->config->get('services.slack.notifications.bot_user_oauth_token'),
        );
    }
}
