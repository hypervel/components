<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Feature;

use Hypervel\Http\Client\Factory;
use Hypervel\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\ImageBlock;
use Hypervel\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Hypervel\Notifications\Slack\SlackChannel;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Tests\SlackNotificationChannel\Slack\TestCase;
use JsonException;
use LogicException;
use RuntimeException;

class SlackMessageTest extends TestCase
{
    public function testExceptionWhenNoTextOrBlock(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Slack messages must contain at least a text message or block.');

        $this->sendNotification(function (SlackMessage $message) {
            $message->to('foo');
        });
    }

    public function testExceptionWhenTooManyBlocks(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Slack messages can only contain up to 50 blocks.');

        $this->sendNotification(function (SlackMessage $message) {
            for ($i = 0; $i < 51; ++$i) {
                $message->dividerBlock();
            }
        });
    }

    public function testSendBasicMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('This is a simple Web API text message. See https://api.slack.com/reference/messaging/payload for more information.');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'This is a simple Web API text message. See https://api.slack.com/reference/messaging/payload for more information.',
        ]);
    }

    public function testExceptionWithInvalidToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Slack API call failed with error [invalid_auth].');

        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION);
        $http->fake(['*' => $http::response(['ok' => false, 'error' => 'invalid_auth'])]);
        $this->slackChannel = new SlackChannel($http);

        $this->sendNotification(function (SlackMessage $message) {
            $message->text('This is a simple Web API text message. See https://api.slack.com/reference/messaging/payload for more information.');
        });
    }

    public function testSetDefaultChannelForMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->to('#general');
        }, null);

        $this->assertNotificationSent([
            'channel' => '#general',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
        ]);
    }

    public function testEmojiAsIconForMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->image('emoji-overrides-image-url-automatically-according-to-spec')->emoji(':ghost:');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'icon_emoji' => ':ghost:',
        ]);
    }

    public function testImageAsIconForMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->emoji('auto-clearing-as-to-prefer-image-since-its-called-after')->image('http://lorempixel.com/48/48');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'icon_url' => 'http://lorempixel.com/48/48',
        ]);
    }

    public function testCanIncludeMetadata(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->metadata('task_created', ['id' => '11223', 'title' => 'Redesign Homepage']);
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'metadata' => [
                'event_type' => 'task_created',
                'event_payload' => ['id' => '11223', 'title' => 'Redesign Homepage'],
            ],
        ]);
    }

    public function testDisableSlackMarkdownParsing(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->disableMarkdownParsing();
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'mrkdwn' => false,
        ]);
    }

    public function testUnfurlLink(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->unfurlLinks();
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'unfurl_links' => true,
        ]);
    }

    public function testUnfurlMedia(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->unfurlMedia();
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'unfurl_media' => true,
        ]);
    }

    public function testCanReplyAsThread(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->threadTimestamp('123456.7890');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'thread_ts' => '123456.7890',
        ]);
    }

    public function testSendThreadedReplyAsBroadcastReference(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->broadcastReply(true);
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'reply_broadcast' => true,
        ]);
    }

    public function testSetBotUserName(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->username('larabot');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'username' => 'larabot',
        ]);
    }

    public function testContainsBothBlocksAndFallbackTextInNotifications(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->text('This is now a fallback text used in notifications. See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->dividerBlock();
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'This is now a fallback text used in notifications. See https://api.slack.com/methods/chat.postMessage for more information.',
            'blocks' => [
                [
                    'type' => 'divider',
                ],
            ],
        ]);
    }

    public function testContainActionBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->actionsBlock(function (ActionsBlock $actions) {
                $actions->button('Cancel')->value('cancel')->id('button_1');
            });
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Cancel',
                            ],
                            'action_id' => 'button_1',
                            'value' => 'cancel',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testContainContextBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->contextBlock(function (ContextBlock $context) {
                $context->image('https://image.freepik.com/free-photo/red-drawing-pin_1156-445.jpg')->alt('images');
            });
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'image',
                            'image_url' => 'https://image.freepik.com/free-photo/red-drawing-pin_1156-445.jpg',
                            'alt_text' => 'images',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testContainDividerBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->dividerBlock();
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'divider',
                ],
            ],
        ]);
    }

    public function testContainHeaderBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->headerBlock('Budget Performance');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Budget Performance',
                    ],
                ],
            ],
        ]);
    }

    public function testContainImageBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->imageBlock('http://placekitten.com/500/500', function (ImageBlock $imageBlock) {
                $imageBlock->alt('An incredibly cute kitten.');
            });
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'image',
                    'image_url' => 'http://placekitten.com/500/500',
                    'alt_text' => 'An incredibly cute kitten.',
                ],
            ],
        ]);
    }

    public function testContainSectionBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->sectionBlock(function (SectionBlock $sectionBlock) {
                $sectionBlock->text('A message *with some bold text* and _some italicized text_.')->markdown();
            });
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => 'A message *with some bold text* and _some italicized text_.',
                    ],
                ],
            ],
        ]);
    }

    public function testAddBlocksConditionally(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->when(true, function (SlackMessage $message) {
                $message->sectionBlock(function (SectionBlock $sectionBlock) {
                    $sectionBlock->text('I *will* be included.')->markdown();
                });
            })->when(false, function (SlackMessage $message) {
                $message->sectionBlock(function (SectionBlock $sectionBlock) {
                    $sectionBlock->text("I *won't* be included.")->markdown();
                });
            })->when(false, function (SlackMessage $message) {
                $message->sectionBlock(function (SectionBlock $sectionBlock) {
                    $sectionBlock->text("I'm *not* included either...")->markdown();
                });
            }, function (SlackMessage $message) {
                $message->sectionBlock(function (SectionBlock $sectionBlock) {
                    $sectionBlock->text('But I *will* be included!')->markdown();
                });
            });
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => 'I *will* be included.',
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => 'But I *will* be included!',
                    ],
                ],
            ],
        ]);
    }

    public function testBlocksInTheOrder(): void
    {
        $this->sendNotification(function (SlackMessage $message) {
            $message->headerBlock('Budget Performance');
            $message->sectionBlock(function (SectionBlock $sectionBlock) {
                $sectionBlock->text('A message *with some bold text* and _some italicized text_.')->markdown();
            });
            $message->headerBlock('Market Performance');
        });

        $this->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Budget Performance',
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => 'A message *with some bold text* and _some italicized text_.',
                    ],
                ],
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Market Performance',
                    ],
                ],
            ],
        ]);
    }

    public function testCopiedBlockKitTemplate(): void
    {
        $payload = [
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'This is a header block',
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'image',
                            'image_url' => 'https://pbs.twimg.com/profile_images/625633822235693056/lNGUneLX_400x400.jpg',
                            'alt_text' => 'cute cat',
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => '*Cat* has approved this message.',
                        ],
                    ],
                ],
                [
                    'type' => 'image',
                    'image_url' => 'https://assets3.thrillist.com/v1/image/1682388/size/tl-horizontal_main.jpg',
                    'alt_text' => 'delicious tacos',
                ],
            ],
        ];

        $this->sendNotification(function (SlackMessage $message) {
            $message->usingBlockKitTemplate(<<<'JSON'
                {
                    "blocks": [
                        {
                            "type": "header",
                            "text": {
                                "type": "plain_text",
                                "text": "This is a header block",
                                "emoji": true
                            }
                        },
                        {
                            "type": "context",
                            "elements": [
                                {
                                    "type": "image",
                                    "image_url": "https://pbs.twimg.com/profile_images/625633822235693056/lNGUneLX_400x400.jpg",
                                    "alt_text": "cute cat"
                                },
                                {
                                    "type": "mrkdwn",
                                    "text": "*Cat* has approved this message."
                                }
                            ]
                        },
                        {
                            "type": "image",
                            "image_url": "https://assets3.thrillist.com/v1/image/1682388/size/tl-horizontal_main.jpg",
                            "alt_text": "delicious tacos"
                        }
                    ]
                }
            JSON);
        });

        $this->assertNotificationSent($payload);
    }

    public function testCombinedBlockKitTemplateAndBlockContractInOrder(): void
    {
        $payload = [
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'This is a header block',
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'divider',
                ],
                [
                    'type' => 'image',
                    'image_url' => 'https://assets3.thrillist.com/v1/image/1682388/size/tl-horizontal_main.jpg',
                    'alt_text' => 'delicious tacos',
                ],
            ],
        ];

        $this->sendNotification(function (SlackMessage $message) {
            $message->usingBlockKitTemplate(<<<'JSON'
                {
                    "blocks": [
                        {
                            "type": "header",
                            "text": {
                                "type": "plain_text",
                                "text": "This is a header block",
                                "emoji": true
                            }
                        }
                    ]
                }
            JSON);

            $message->dividerBlock();

            $message->usingBlockKitTemplate(<<<'JSON'
                {
                    "blocks": [
                        {
                            "type": "image",
                            "image_url": "https://assets3.thrillist.com/v1/image/1682388/size/tl-horizontal_main.jpg",
                            "alt_text": "delicious tacos"
                        }
                    ]
                }
            JSON);
        });

        $this->assertNotificationSent($payload);
    }

    public function testCanReturnABlockKitBuilderUrl(): void
    {
        $message = (new SlackMessage)
            ->username('hyperbot')
            ->to('#ghost-talk')
            ->headerBlock('Budget Performance')
            ->sectionBlock(function (SectionBlock $sectionBlock) {
                $sectionBlock->text('A message *with some bold text* and _some italicized text_.')->markdown();
            });

        $expectedUrl = 'https://app.slack.com/block-kit-builder#' . rawurlencode(
            '{"blocks":[{"type":"header","text":{"type":"plain_text","text":"Budget Performance"}},'
            . '{"type":"section","text":{"type":"mrkdwn","text":"A message *with some bold text* and _some italicized text_."}}]}'
        );

        $this->assertSame($expectedUrl, $message->toBlockKitBuilderUrl());
    }

    public function testBlockKitBuilderUrlReportsJsonEncodingFailures(): void
    {
        $message = (new SlackMessage)
            ->text('Invoice paid')
            ->metadata('invoice.paid', ['reference' => "\xFF"]);

        $this->expectException(JsonException::class);
        $this->expectExceptionMessageIsOrContains('Malformed UTF-8 characters');

        $message->toBlockKitBuilderUrl();
    }
}
