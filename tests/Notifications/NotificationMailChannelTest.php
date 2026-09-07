<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Contracts\Mail\Factory as MailFactory;
use Hypervel\Mail\Markdown;
use Hypervel\Mail\Message;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

class NotificationMailChannelTest extends TestCase
{
    public function testBuildMessageAddsTagsAndStringifiesMetadata(): void
    {
        $channel = new MailChannel(
            m::mock(MailFactory::class),
            m::mock(Markdown::class),
        );
        $message = new Email;
        $notifiable = new class {
            public function routeNotificationFor(string $driver, Notification $notification): string
            {
                return 'taylor@hypervel.org';
            }
        };
        $notification = new Notification;
        $mailMessage = (new MailMessage)
            ->tag('release')
            ->metadata('attempts', 2);

        (new ClassInvoker($channel))->buildMessage(
            new Message($message),
            $notifiable,
            $notification,
            $mailMessage,
        );

        $tagHeader = $message->getHeaders()->get('X-Tag');
        $this->assertInstanceOf(TagHeader::class, $tagHeader);
        $this->assertSame('release', $tagHeader->getBody());

        $metadataHeader = $message->getHeaders()->get('X-Metadata-attempts');
        $this->assertInstanceOf(MetadataHeader::class, $metadataHeader);
        $this->assertSame('2', $metadataHeader->getBody());
    }
}
