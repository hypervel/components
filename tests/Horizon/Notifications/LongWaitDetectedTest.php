<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Notifications;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\Notifications\LongWaitDetected;
use Hypervel\Notifications\Messages\SlackMessage as LegacySlackMessage;
use Hypervel\Notifications\Slack\SlackMessage;
use Hypervel\Tests\TestCase;

class LongWaitDetectedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('config', new ConfigRepository([
            'horizon' => ['name' => 'Horizon Test'],
        ]));

        Container::setInstance($container);
    }

    public function testWebhookRoutesRetainTheLegacyAttachmentPayload(): void
    {
        Horizon::routeSlackNotificationsTo('https://hooks.slack.test', '#alerts');

        $message = (new LongWaitDetected('redis', 'critical', 90))->toSlack(null);

        $this->assertInstanceOf(LegacySlackMessage::class, $message);
        $this->assertSame('#alerts', $message->channel);
        $this->assertSame('Hypervel Horizon', $message->username);
        $this->assertSame('danger', $message->color());
        $this->assertSame('Oh no! Something needs your attention.', $message->content);
        $this->assertCount(1, $message->attachments);
        $this->assertSame('Long Wait Detected', $message->attachments[0]->title);
        $this->assertSame(
            '[Horizon Test] The "critical" queue on the "redis" connection has a wait time of 90 seconds.',
            $message->attachments[0]->content,
        );
    }

    public function testWebApiRoutesRetainTheModernBlockKitPayload(): void
    {
        Horizon::routeSlackNotificationsTo('slack-channel-id');

        $message = (new LongWaitDetected('redis', 'critical', 90))->toSlack(null);

        $this->assertInstanceOf(SlackMessage::class, $message);
        $this->assertSame([
            'channel' => null,
            'text' => 'Oh no! Something needs your attention.',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Long Wait Detected',
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '[Horizon Test] The "critical" queue on the "redis" connection has a wait time of 90 seconds.',
                    ],
                ],
            ],
            'username' => 'Hypervel Horizon',
        ], $message->toArray());
    }
}
