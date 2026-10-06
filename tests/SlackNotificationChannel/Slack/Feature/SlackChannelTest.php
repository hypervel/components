<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Feature;

use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Notifications\Slack\SlackChannel;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotification;
use Hypervel\Tests\SlackNotificationChannel\Slack\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class SlackChannelTest extends TestCase
{
    public function testAnHttpFakeInterceptsAnAlreadyResolvedChannel(): void
    {
        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION);
        $channel = new SlackChannel($http);
        $http->fake(['*' => $http::response(['ok' => true, 'ts' => '123.456'])]);

        $response = $channel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('#general', 'token')),
            new SlackChannelTestNotification(fn (SlackMessage $message) => $message->text('Content')),
        );

        $this->assertSame('123.456', $response->json('ts'));
        $http->assertSentCount(1);
    }

    #[DataProvider('connectionOptions')]
    public function testItUsesTheNamedConnectionAndHttpDefaults(array $preset, int $timeout): void
    {
        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION, $preset);
        $options = [];
        $http->fake(function (Request $request, array $requestOptions) use (&$options, $http) {
            $options = $requestOptions;

            return $http::response(['ok' => true]);
        });

        (new SlackChannel($http))->send(
            new SlackChannelTestNotifiable(SlackRoute::make('#general', 'token')),
            new SlackChannelTestNotification(fn (SlackMessage $message) => $message->text('Content')),
        );

        $this->assertSame(10, $options['connect_timeout']);
        $this->assertSame($timeout, $options['timeout']);
        $http->assertSent(fn (Request $request): bool => $request->hasHeader('Content-Type', 'application/json')
            && ($preset === [] || $request->hasHeader('X-Slack-Connection', 'yes')));
    }

    /**
     * Provide default and customized Slack connection options.
     */
    public static function connectionOptions(): array
    {
        return [
            'defaults' => [[], 30],
            'custom connection' => [['timeout' => 8, 'headers' => ['X-Slack-Connection' => 'yes']], 8],
        ];
    }

    public function testSeparateDeliveriesKeepTheirOwnTokenAndPayload(): void
    {
        foreach (['first', 'second'] as $workspace) {
            $this->slackChannel->send(
                new SlackChannelTestNotifiable(SlackRoute::make("#{$workspace}", "{$workspace}-token")),
                new SlackChannelTestNotification(fn (SlackMessage $message) => $message->text($workspace)),
            );
        }

        $this->http->assertSentCount(2);
        $this->http->assertSentInOrder([
            fn (Request $request): bool => $request->data() === ['channel' => '#first', 'text' => 'first']
                && $request->hasHeader('Authorization', 'Bearer first-token'),
            fn (Request $request): bool => $request->data() === ['channel' => '#second', 'text' => 'second']
                && $request->hasHeader('Authorization', 'Bearer second-token'),
        ]);
    }

    #[DataProvider('failedStatuses')]
    public function testHttpFailuresUseTheHttpClientException(int $status): void
    {
        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION);
        $http->fake(['*' => $http::response(['ok' => false, 'error' => 'failed'], $status)]);
        $this->slackChannel = new SlackChannel($http);

        $this->expectException(RequestException::class);

        $this->sendNotification(fn (SlackMessage $message) => $message->text('Content'));
    }

    /**
     * Provide client and server failure statuses.
     */
    public static function failedStatuses(): array
    {
        return [[429], [503]];
    }

    public function testConnectionFailuresUseTheHttpClientException(): void
    {
        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION);
        $http->fake(['*' => $http::failedConnection('Slack is unreachable')]);
        $this->slackChannel = new SlackChannel($http);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageIsOrContains('Slack is unreachable');

        $this->sendNotification(fn (SlackMessage $message) => $message->text('Content'));
    }

    #[DataProvider('successfulResponses')]
    public function testSuccessfulResponsesOnlyRejectAnExplicitFalseOk(array|string $body): void
    {
        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION);
        $http->fake(['*' => $http::response($body)]);

        $response = (new SlackChannel($http))->send(
            new SlackChannelTestNotifiable(SlackRoute::make('#general', 'token')),
            new SlackChannelTestNotification(fn (SlackMessage $message) => $message->text('Content')),
        );

        $this->assertTrue($response->successful());
    }

    /**
     * Provide successful responses without a Slack API failure.
     */
    public static function successfulResponses(): array
    {
        return [[['ok' => true]], [[]], ['not json']];
    }

    public function testTheRouteNotificationForSlackMethodDescribesTheChannelUsingAString(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable('example-channel'),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content')->to('ignored-channel');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'example-channel',
            'text' => 'Content',
        ], 'config-set-token');
    }

    public function testTheRouteNotificationForSlackMethodDescribesTheChannelUsingASlackRouteInstance(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('route-set-channel')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'route-set-channel',
            'text' => 'Content',
        ], 'config-set-token');
    }

    public function testTheRouteNotificationForSlackMethodDescribesTheChannelAndTokenUsingASlackRouteInstance(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('route-set-channel', 'route-set-token')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'route-set-channel',
            'text' => 'Content',
        ], 'route-set-token');
    }

    public function testTheRouteNotificationForSlackMethodOnlyDescribesTheTokenUsingASlackRouteInstance(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'ignored-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make(null, 'route-set-token')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content')->to('notification-channel');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'notification-channel',
            'text' => 'Content',
        ], 'route-set-token');
    }

    public function testNoRouteUsesTheConfiguredChannelAndToken(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');
        config()->set('services.slack.notifications.channel', 'config-set-channel');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable,
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'config-set-channel',
            'text' => 'Content',
        ], 'config-set-token');
    }

    public function testEmptySlackRouteUsesTheConfiguredChannelAndToken(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');
        config()->set('services.slack.notifications.channel', 'config-set-channel');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make()),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'config-set-channel',
            'text' => 'Content',
        ], 'config-set-token');
    }

    public function testItPrefersTheNotificationDefinedChannelOverTheConfigDefinedChannel(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');
        config()->set('services.slack.notifications.channel', 'config-set-channel');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable,
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content')->to('notification-channel');
            }),
        );

        $this->assertNotificationSent([
            'channel' => 'notification-channel',
            'text' => 'Content',
        ], 'config-set-token');
    }

    public function testItThrowsAnExceptionWhenTheRouteNotificationForSlackMethodDoesNotProvideAChannelAndTheNotificationAndConfigDoNotEither(): void
    {
        config()->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Slack notification channel is not set.');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable,
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }

    public function testItThrowsAnExceptionWhenTheRouteNotificationForSlackMethodDoesNotProvideATokenAndTheConfigDoesNotEither(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Slack API authentication token is not set.');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('hypervel-channel')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }
}
