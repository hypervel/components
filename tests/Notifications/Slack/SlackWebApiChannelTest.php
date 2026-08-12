<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack;

use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Tests\Notifications\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\Notifications\Slack\Fixtures\SlackChannelTestNotification;
use LogicException;

class SlackWebApiChannelTest extends TestCase
{
    public function testTheRouteNotificationForSlackMethodDescribesTheChannelUsingAString(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->assertNotificationSent([
            'channel' => 'example-channel',
            'text' => 'Content',
        ], [], 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable('example-channel'),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content')->to('ignored-channel');
            }),
        );
    }

    public function testTheRouteNotificationForSlackMethodDescribesTheChannelUsingASlackRouteInstance(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->assertNotificationSent([
            'channel' => 'route-set-channel',
            'text' => 'Content',
        ], [], 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('route-set-channel')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }

    public function testTheRouteNotificationForSlackMethodDescribesTheChannelAndTokenUsingASlackRouteInstance(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->assertNotificationSent([
            'channel' => 'route-set-channel',
            'text' => 'Content',
        ], [], 'route-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('route-set-channel', 'route-set-token')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }

    public function testTheRouteNotificationForSlackMethodOnlyDescribesTheTokenUsingASlackRouteInstance(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'ignored-token');

        $this->assertNotificationSent([
            'channel' => 'notification-channel',
            'text' => 'Content',
        ], [], 'route-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make(null, 'route-set-token')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content')->to('notification-channel');
            }),
        );
    }

    public function testNoRouteUsesTheConfiguredChannelAndToken(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');
        $this->config->set('services.slack.notifications.channel', 'config-set-channel');

        $this->assertNotificationSent([
            'channel' => 'config-set-channel',
            'text' => 'Content',
        ], [], 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable,
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }

    public function testEmptySlackRouteUsesTheConfiguredChannelAndToken(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');
        $this->config->set('services.slack.notifications.channel', 'config-set-channel');

        $this->assertNotificationSent([
            'channel' => 'config-set-channel',
            'text' => 'Content',
        ], [], 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make()),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }

    public function testItPrefersTheNotificationDefinedChannelOverTheConfigDefinedChannel(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');
        $this->config->set('services.slack.notifications.channel', 'config-set-channel');

        $this->assertNotificationSent([
            'channel' => 'notification-channel',
            'text' => 'Content',
        ], [], 'config-set-token');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable,
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content')->to('notification-channel');
            }),
        );
    }

    public function testItThrowsAnExceptionWhenTheRouteNotificationForSlackMethodDoesNotProvideAChannelAndTheNotificationAndConfigDoNotEither(): void
    {
        $this->config->set('services.slack.notifications.bot_user_oauth_token', 'config-set-token');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Slack notification channel is not set.');

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
        $this->expectExceptionMessage('Slack API authentication token is not set.');

        $this->slackChannel->send(
            new SlackChannelTestNotifiable(SlackRoute::make('hypervel-channel')),
            new SlackChannelTestNotification(function (SlackMessage $message) {
                $message->text('Content');
            }),
        );
    }
}
