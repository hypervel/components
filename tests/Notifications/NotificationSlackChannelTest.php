<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Messages\SlackAttachment;
use Hypervel\Notifications\Messages\SlackAttachmentField;
use Hypervel\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Notifications\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class NotificationSlackChannelTest extends TestCase
{
    #[DataProvider('payloadDataProvider')]
    public function testCorrectPayloadIsSentToSlack(Notification $notification, array $payload): void
    {
        $guzzleHttp = m::mock(Client::class);

        $slackChannel = new SlackWebhookChannel($guzzleHttp);

        $guzzleHttp->shouldReceive('post')->andReturnUsing(function (string $argUrl, array $argPayload) use ($payload) {
            $this->assertSame('url', $argUrl);
            $this->assertEquals($payload, $argPayload);

            return new Response;
        });

        $slackChannel->send(new SlackChannelTestNotifiable('url'), $notification);
    }

    public static function payloadDataProvider(): array
    {
        return [
            'payloadWithIcon' => static::getPayloadWithIcon(),
            'payloadWithImageIcon' => static::getPayloadWithImageIcon(),
            'payloadWithoutOptionalFields' => static::getPayloadWithoutOptionalFields(),
            'payloadWithAttachmentFieldBuilder' => static::getPayloadWithAttachmentFieldBuilder(),
        ];
    }

    public function testModernBlockKitPayloadIsSentToSlack(): void
    {
        $guzzleHttp = m::mock(Client::class);
        $notification = new class extends Notification {
            public function toSlack(mixed $notifiable): SlackMessage
            {
                return (new SlackMessage)->text('Modern content');
            }
        };

        $guzzleHttp->shouldReceive('post')->once()->with('url', [
            'json' => [
                'channel' => null,
                'text' => 'Modern content',
            ],
        ])->andReturn(new Response);

        $response = (new SlackWebhookChannel($guzzleHttp))->send(
            new SlackChannelTestNotifiable('url'),
            $notification,
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    protected static function getPayloadWithIcon(): array
    {
        return [
            new NotificationSlackChannelTestNotification,
            [
                'json' => [
                    'username' => 'Ghostbot',
                    'icon_emoji' => ':ghost:',
                    'channel' => '#ghost-talk',
                    'text' => 'Content',
                    'attachments' => [
                        [
                            'title' => 'Hypervel',
                            'title_link' => 'https://hypervel.org',
                            'text' => 'Attachment Content',
                            'fallback' => 'Attachment Fallback',
                            'fields' => [
                                [
                                    'title' => 'Project',
                                    'value' => 'Hypervel',
                                    'short' => true,
                                ],
                            ],
                            'mrkdwn_in' => ['text'],
                            'footer' => 'Hypervel',
                            'footer_icon' => 'https://hypervel.org/fake.png',
                            'author_name' => 'Author',
                            'author_link' => 'https://hypervel.org/fake_author',
                            'author_icon' => 'https://hypervel.org/fake_author.png',
                            'ts' => 1234567890,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function getPayloadWithImageIcon(): array
    {
        return [
            new NotificationSlackChannelTestNotificationWithImageIcon,
            [
                'json' => [
                    'username' => 'Ghostbot',
                    'icon_url' => 'http://example.com/image.png',
                    'channel' => '#ghost-talk',
                    'text' => 'Content',
                    'attachments' => [
                        [
                            'title' => 'Hypervel',
                            'title_link' => 'https://hypervel.org',
                            'text' => 'Attachment Content',
                            'fallback' => 'Attachment Fallback',
                            'fields' => [
                                [
                                    'title' => 'Project',
                                    'value' => 'Hypervel',
                                    'short' => true,
                                ],
                            ],
                            'mrkdwn_in' => ['text'],
                            'footer' => 'Hypervel',
                            'footer_icon' => 'https://hypervel.org/fake.png',
                            'ts' => 1234567890,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function getPayloadWithoutOptionalFields(): array
    {
        return [
            new NotificationSlackChannelWithoutOptionalFieldsTestNotification,
            [
                'json' => [
                    'text' => 'Content',
                    'attachments' => [
                        [
                            'title' => 'Hypervel',
                            'title_link' => 'https://hypervel.org',
                            'text' => 'Attachment Content',
                            'fields' => [
                                [
                                    'title' => 'Project',
                                    'value' => 'Hypervel',
                                    'short' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected static function getPayloadWithAttachmentFieldBuilder(): array
    {
        return [
            new NotificationSlackChannelWithAttachmentFieldBuilderTestNotification,
            [
                'json' => [
                    'text' => 'Content',
                    'attachments' => [
                        [
                            'title' => 'Hypervel',
                            'text' => 'Attachment Content',
                            'title_link' => 'https://hypervel.org',
                            'callback_id' => 'attachment_callbackid',
                            'fields' => [
                                [
                                    'title' => 'Project',
                                    'value' => 'Hypervel',
                                    'short' => true,
                                ],
                                [
                                    'title' => 'Special powers',
                                    'value' => 'Zonda',
                                    'short' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

class NotificationSlackChannelTestNotification extends Notification
{
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->from('Ghostbot', ':ghost:')
            ->to('#ghost-talk')
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment) {
                $timestamp = CarbonImmutable::createFromTimestamp(1234567890);
                $attachment->title('Hypervel', 'https://hypervel.org')
                    ->content('Attachment Content')
                    ->fallback('Attachment Fallback')
                    ->fields([
                        'Project' => 'Hypervel',
                    ])
                    ->footer('Hypervel')
                    ->footerIcon('https://hypervel.org/fake.png')
                    ->markdown(['text'])
                    ->author('Author', 'https://hypervel.org/fake_author', 'https://hypervel.org/fake_author.png')
                    ->timestamp($timestamp);
            });
    }
}

class NotificationSlackChannelTestNotificationWithImageIcon extends Notification
{
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->from('Ghostbot')
            ->image('http://example.com/image.png')
            ->to('#ghost-talk')
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment) {
                $timestamp = CarbonImmutable::createFromTimestamp(1234567890);
                $attachment->title('Hypervel', 'https://hypervel.org')
                    ->content('Attachment Content')
                    ->fallback('Attachment Fallback')
                    ->fields([
                        'Project' => 'Hypervel',
                    ])
                    ->footer('Hypervel')
                    ->footerIcon('https://hypervel.org/fake.png')
                    ->markdown(['text'])
                    ->timestamp($timestamp);
            });
    }
}

class NotificationSlackChannelWithoutOptionalFieldsTestNotification extends Notification
{
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment) {
                $attachment->title('Hypervel', 'https://hypervel.org')
                    ->content('Attachment Content')
                    ->fields([
                        'Project' => 'Hypervel',
                    ]);
            });
    }
}

class NotificationSlackChannelWithAttachmentFieldBuilderTestNotification extends Notification
{
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment) {
                $attachment->title('Hypervel', 'https://hypervel.org')
                    ->content('Attachment Content')
                    ->field('Project', 'Hypervel')
                    ->callbackId('attachment_callbackid')
                    ->field(function (SlackAttachmentField $attachmentField) {
                        $attachmentField
                            ->title('Special powers')
                            ->content('Zonda')
                            ->long();
                    });
            });
    }
}
