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
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotification;
use Hypervel\Tests\SlackNotificationChannel\Slack\TestCase;
use JsonException;
use LogicException;
use RuntimeException;

class SlackMessageTest extends TestCase
{
    public function testItThrowsAnExceptionWhenNoTextOrBlockWasProvided(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Slack messages must contain at least a text message or block.');

        $this->sendNotification(function (SlackMessage $message): void {
            $message->to('foo');
        });
    }

    public function testItThrowsAnExceptionWhenTooManyBlocksAreDefined(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Slack messages can only contain up to 50 blocks.');

        $this->sendNotification(function (SlackMessage $message): void {
            for ($i = 0; $i < 51; ++$i) {
                $message->dividerBlock();
            }
        });
    }

    public function testItSendsAVeryBasicMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('This is a simple Web API text message. See https://api.slack.com/reference/messaging/payload for more information.');
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'This is a simple Web API text message. See https://api.slack.com/reference/messaging/payload for more information.',
        ]);
    }

    public function testItFailsToSendAMessageWhenTheSlackAppIsNotConfiguredCorrectly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Slack API call failed with error [invalid_auth].');

        $http = new Factory;
        $http->registerConnection(SlackChannel::CONNECTION);
        $http->fake(['*' => $http::response(['ok' => false, 'error' => 'invalid_auth'])]);
        $this->slackChannel = new SlackChannel($http);

        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('This is a simple Web API text message. See https://api.slack.com/reference/messaging/payload for more information.');
        });
    }

    public function testItCanSetTheDefaultChannelForTheMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->to('#general');
        }, null)->assertNotificationSent([
            'channel' => '#general',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
        ]);
    }

    public function testItCanUseAnEmojiAsTheIconForTheMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->image('emoji-overrides-image-url-automatically-according-to-spec')->emoji(':ghost:');
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'icon_emoji' => ':ghost:',
        ]);
    }

    public function testItCanUseAnImageAsTheIconForTheMessage(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->emoji('auto-clearing-as-to-prefer-image-since-its-called-after')->image('http://lorempixel.com/48/48');
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'icon_url' => 'http://lorempixel.com/48/48',
        ]);
    }

    public function testItCanIncludeMetadata(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->metadata('task_created', ['id' => '11223', 'title' => 'Redesign Homepage']);
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'metadata' => [
                'event_type' => 'task_created',
                'event_payload' => ['id' => '11223', 'title' => 'Redesign Homepage'],
            ],
        ]);
    }

    public function testItCanDisableSlackMarkdownParsing(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->disableMarkdownParsing();
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'mrkdwn' => false,
        ]);
    }

    public function testItCanUnfurlLinks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->unfurlLinks();
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'unfurl_links' => true,
        ]);
    }

    public function testItCanUnfurlMedia(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->unfurlMedia();
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'unfurl_media' => true,
        ]);
    }

    public function testItCanReplyAsThread(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->threadTimestamp('123456.7890');
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'thread_ts' => '123456.7890',
        ]);
    }

    public function testItCanSendThreadedReplyAsBroadcastReference(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->broadcastReply(true);
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'reply_broadcast' => true,
        ]);
    }

    public function testItCanSetTheBotUserName(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->username('larabot');
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'See https://api.slack.com/methods/chat.postMessage for more information.',
            'username' => 'larabot',
        ]);
    }

    public function testItContainsBothBlocksAndAFallbackTextUsedInNotificationsOnly(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->text('This is now a fallback text used in notifications. See https://api.slack.com/methods/chat.postMessage for more information.');
            $message->dividerBlock();
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'text' => 'This is now a fallback text used in notifications. See https://api.slack.com/methods/chat.postMessage for more information.',
            'blocks' => [
                [
                    'type' => 'divider',
                ],
            ],
        ]);
    }

    public function testItCanContainActionBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->actionsBlock(function (ActionsBlock $actions): void {
                $actions->button('Cancel')->value('cancel')->id('button_1');
            });
        })->assertNotificationSent([
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

    public function testItCanContainContextBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->contextBlock(function (ContextBlock $context): void {
                $context->image('https://image.freepik.com/free-photo/red-drawing-pin_1156-445.jpg')->alt('images');
            });
        })->assertNotificationSent([
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

    public function testItCanContainDividerBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->dividerBlock();
        })->assertNotificationSent([
            'channel' => '#ghost-talk',
            'blocks' => [
                [
                    'type' => 'divider',
                ],
            ],
        ]);
    }

    public function testItCanContainHeaderBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->headerBlock('Budget Performance');
        })->assertNotificationSent([
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

    public function testItCanContainImageBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->imageBlock('http://placekitten.com/500/500', function (ImageBlock $imageBlock): void {
                $imageBlock->alt('An incredibly cute kitten.');
            });
        })->assertNotificationSent([
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

    public function testItCanContainSectionBlocks(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->sectionBlock(function (SectionBlock $sectionBlock): void {
                $sectionBlock->text('A message *with some bold text* and _some italicized text_.')->markdown();
            });
        })->assertNotificationSent([
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

    public function testItCanAddBlocksConditionally(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->when(true, function (SlackMessage $message): void {
                $message->sectionBlock(function (SectionBlock $sectionBlock): void {
                    $sectionBlock->text('I *will* be included.')->markdown();
                });
            })->when(false, function (SlackMessage $message): void {
                $message->sectionBlock(function (SectionBlock $sectionBlock): void {
                    $sectionBlock->text("I *won't* be included.")->markdown();
                });
            })->when(false, function (SlackMessage $message): void {
                $message->sectionBlock(function (SectionBlock $sectionBlock): void {
                    $sectionBlock->text("I'm *not* included either...")->markdown();
                });
            }, function (SlackMessage $message): void {
                $message->sectionBlock(function (SectionBlock $sectionBlock): void {
                    $sectionBlock->text('But I *will* be included!')->markdown();
                });
            });
        })->assertNotificationSent([
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

    public function testItSubmitsBlocksInTheOrderTheyWereDefined(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
            $message->headerBlock('Budget Performance');
            $message->sectionBlock(function (SectionBlock $sectionBlock): void {
                $sectionBlock->text('A message *with some bold text* and _some italicized text_.')->markdown();
            });
            $message->headerBlock('Market Performance');
        })->assertNotificationSent([
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

    public function testItCanUseCopiedBlockKitTemplate(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
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
        })->assertNotificationSent([
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
        ]);
    }

    public function testItCanCombinedBlockKitTemplateAndBlockContractInOrder(): void
    {
        $this->sendNotification(function (SlackMessage $message): void {
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
        })->assertNotificationSent([
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
        ]);
    }

    public function testItCanReturnAnBlockKitBuilderUrl(): void
    {
        $message = (new SlackChannelTestNotification(function (SlackMessage $message): void {
            $message
                ->username('larabot')
                ->to('#ghost-talk')
                ->headerBlock('Budget Performance')
                ->sectionBlock(function (SectionBlock $sectionBlock): void {
                    $sectionBlock->text('A message *with some bold text* and _some italicized text_.')->markdown();
                });
        }))->toSlack(
            new SlackChannelTestNotifiable(new SlackRoute('#ghost-talk', 'fake-token'))
        );

        $returnedUrl = $message->toBlockKitBuilderUrl();
        $expectedUrl = 'https://app.slack.com/block-kit-builder#'
            . rawurlencode('{"blocks":[{"type":"header","text":{"type":"plain_text","text":"Budget Performance"}},{"type":"section","text":{"type":"mrkdwn","text":"A message *with some bold text* and _some italicized text_."}}]}');

        $this->assertStringNotContainsStringIgnoringCase('username', $returnedUrl);
        $this->assertStringNotContainsStringIgnoringCase('larabot', $returnedUrl);
        $this->assertStringNotContainsStringIgnoringCase('channel', $returnedUrl);
        $this->assertStringNotContainsStringIgnoringCase('#ghost-talk', $returnedUrl);

        $this->assertSame($expectedUrl, $returnedUrl);
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
