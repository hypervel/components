<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Messages\SlackAttachment;
use Hypervel\Notifications\Messages\SlackAttachmentField;
use Hypervel\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotifiable;
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

        $guzzleHttp->shouldReceive('post')->andReturnUsing(function (string $argUrl, array $argPayload) use ($payload): Response {
            $this->assertSame('url', $argUrl);
            $this->assertEquals($payload, $argPayload);

            return new Response;
        });

        $slackChannel->send(new SlackChannelTestNotifiable('url'), $notification);
    }

    /**
     * Provide legacy notifications and their webhook payloads.
     */
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
            /**
             * Get the Slack representation of the notification.
             */
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

    #[DataProvider('unfurlOptions')]
    public function testExplicitUnfurlOptionsArePreserved(bool $enabled): void
    {
        $message = (new LegacySlackMessage)
            ->content('Content')
            ->unfurlLinks($enabled)
            ->unfurlMedia($enabled);

        $payload = (new SlackWebhookChannel(new Client))->buildJsonPayload($message);

        $this->assertSame($enabled, $payload['json']['unfurl_links']);
        $this->assertSame($enabled, $payload['json']['unfurl_media']);
    }

    /**
     * Provide explicit unfurl options.
     */
    public static function unfurlOptions(): array
    {
        return ['enabled' => [true], 'disabled' => [false]];
    }

    public function testUnsetUnfurlOptionsKeepTheDefaultPayload(): void
    {
        $message = (new LegacySlackMessage)->content('Content');

        $payload = (new SlackWebhookChannel(new Client))->buildJsonPayload($message);

        $this->assertSame(['text' => 'Content', 'attachments' => []], $payload['json']);
    }

    public function testClearingTheChannelRemovesTheWebhookOverride(): void
    {
        $message = (new LegacySlackMessage)->content('Content')->to('#alerts');

        $this->assertSame($message, $message->to(null));

        $payload = (new SlackWebhookChannel(new Client))->buildJsonPayload($message);

        $this->assertArrayNotHasKey('channel', $payload['json']);
    }

    public function testZeroAttachmentValuesArePreserved(): void
    {
        $message = (new LegacySlackMessage)->content('Content')->attachment(function (SlackAttachment $attachment): void {
            $attachment->title('0')->content('0')->callbackId('0')
                ->timestamp(CarbonImmutable::createFromTimestamp(0));
        });

        $payload = (new SlackWebhookChannel(new Client))->buildJsonPayload($message);

        $this->assertSame([
            'callback_id' => '0',
            'text' => '0',
            'title' => '0',
            'ts' => 0,
        ], $payload['json']['attachments'][0]);
    }

    public function testAttachmentFieldTitlesThatNamePhpFunctionsAreNotCalled(): void
    {
        $message = (new LegacySlackMessage)->content('Content')->attachment(function (SlackAttachment $attachment): void {
            $attachment->field('Date', '2026-10-08')->field('Count', '3');
        });

        $payload = (new SlackWebhookChannel(new Client))->buildJsonPayload($message);

        $this->assertSame([
            ['title' => 'Date', 'value' => '2026-10-08', 'short' => true],
            ['title' => 'Count', 'value' => '3', 'short' => true],
        ], $payload['json']['attachments'][0]['fields']);
    }

    public function testDisabledWebhookDoesNotRequireASlackMessageMethod(): void
    {
        $http = m::mock(Client::class);
        $http->shouldNotReceive('post');

        $this->assertNull((new SlackWebhookChannel($http))->send(
            new SlackChannelTestNotifiable,
            new Notification,
        ));
    }

    public function testRawWebhookRequestOptionsArePreserved(): void
    {
        $message = (new LegacySlackMessage)->content('Content');
        $message->http = ['timeout' => 12, 'headers' => ['X-Notification' => 'slack']];

        $payload = (new SlackWebhookChannel(new Client))->buildJsonPayload($message);

        $this->assertSame(12, $payload['timeout']);
        $this->assertSame(['X-Notification' => 'slack'], $payload['headers']);
    }

    /**
     * Get a notification with an emoji icon and its payload.
     */
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

    /**
     * Get a notification with an image icon and its payload.
     */
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

    /**
     * Get a notification without optional fields and its payload.
     */
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

    /**
     * Get a notification using the attachment field builder and its payload.
     */
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
    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->from('Ghostbot', ':ghost:')
            ->to('#ghost-talk')
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment): void {
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
    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->from('Ghostbot')
            ->image('http://example.com/image.png')
            ->to('#ghost-talk')
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment): void {
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
    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment): void {
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
    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): LegacySlackMessage
    {
        return (new LegacySlackMessage)
            ->content('Content')
            ->attachment(function (SlackAttachment $attachment): void {
                $attachment->title('Hypervel', 'https://hypervel.org')
                    ->content('Attachment Content')
                    ->field('Project', 'Hypervel')
                    ->callbackId('attachment_callbackid')
                    ->field(function (SlackAttachmentField $attachmentField): void {
                        $attachmentField
                            ->title('Special powers')
                            ->content('Zonda')
                            ->long();
                    });
            });
    }
}
