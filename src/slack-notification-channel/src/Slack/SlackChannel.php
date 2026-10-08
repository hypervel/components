<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Slack;

use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Response;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Config;
use LogicException;
use RuntimeException;

class SlackChannel
{
    /**
     * The HTTP connection used to send Slack API messages.
     */
    public const string CONNECTION = 'slack-notifications';

    /**
     * Create a new Slack channel instance.
     */
    public function __construct(
        protected Factory $http
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?Response
    {
        $route = $this->determineRoute($notifiable, $notification);

        $message = $notification->toSlack($notifiable); // @phpstan-ignore method.notFound

        $payload = $this->buildJsonPayload($message, $route);

        if (! $payload['channel']) {
            throw new LogicException('Slack notification channel is not set.');
        }

        if (! $route->token) {
            throw new LogicException('Slack API authentication token is not set.');
        }

        $response = $this->http->connection(self::CONNECTION)
            ->asJson()
            ->withToken($route->token)
            ->post('https://slack.com/api/chat.postMessage', $payload)
            ->throw();

        if ($response->successful() && $response->json('ok') === false) {
            throw new RuntimeException('Slack API call failed with error [' . $response->json('error') . '].');
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
            'channel' => $route->channel ?? $payload['channel'] ?? Config::get('services.slack.notifications.channel'),
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
            return SlackRoute::make($route, Config::get('services.slack.notifications.bot_user_oauth_token'));
        }

        return SlackRoute::make(
            $route->channel ?? null,
            $route->token ?? Config::get('services.slack.notifications.bot_user_oauth_token'),
        );
    }
}
