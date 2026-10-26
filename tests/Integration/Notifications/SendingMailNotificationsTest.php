<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Mail\Events\MessageSending;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailables\Content;
use Hypervel\Mail\Mailables\Envelope;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Notifications\Fixtures\Models\NotifiableUser;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[WithConfig('database.default', 'testing')]
class SendingMailNotificationsTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');
        $config->set('mail.default', 'array');
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('mail.mailers.foo', ['transport' => 'array']);

        $app->make('view')->addLocation(__DIR__ . '/Fixtures');
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('name')->nullable();
        });
    }

    public function testMailIsSent(): void
    {
        $notification = new TestMailNotification;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $sending = $this->captureSendingData();

        $user->notify($notification);

        $message = $this->sentMessage('foo');

        $this->assertSame([['taylor@hypervel.com', '']], $this->addresses($message->getTo()));
        $this->assertSame([['cc@deepblue.com', 'cc']], $this->addresses($message->getCc()));
        $this->assertSame([['bcc@deepblue.com', 'bcc']], $this->addresses($message->getBcc()));
        $this->assertSame([['jack@deepblue.com', 'Jacques Mayol']], $this->addresses($message->getFrom()));
        $this->assertSame([['jack@deepblue.com', 'Jacques Mayol']], $this->addresses($message->getReplyTo()));
        $this->assertSame('Test Mail Notification', $message->getSubject());
        $this->assertSame(1, $message->getPriority());
        $this->assertStringContainsString('The introduction to the notification.', $message->getHtmlBody());
        $this->assertStringContainsString('The introduction to the notification.', $message->getTextBody());

        $this->assertSame([
            '__hypervel_notification_id' => $notification->id,
            '__hypervel_notification' => TestMailNotification::class,
            '__hypervel_notification_queued' => false,
        ], Arr::only($sending->data, [
            '__hypervel_notification_id', '__hypervel_notification', '__hypervel_notification_queued',
        ]));
        $this->assertSame($notification->toMail($user)->toArray(), Arr::except($sending->data, [
            'message', 'mailer', '__hypervel_notification_id', '__hypervel_notification', '__hypervel_notification_queued',
        ]));
    }

    public function testMailIsSentWithCustomTheme(): void
    {
        $notification = new TestMailNotificationWithCustomTheme;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage('foo');

        $this->assertSame('Test Mail Notification With Custom Theme', $message->getSubject());
        $this->assertStringContainsString('#123456', $message->getHtmlBody());
    }

    public function testMailUsesDefaultThemeWhenNoneIsGiven(): void
    {
        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify(new TestMailNotificationWithSubject);

        $this->assertStringNotContainsString('#123456', $this->sentMessage()->getHtmlBody());
    }

    public function testMailIsSentToNamedAddress(): void
    {
        $user = NotifiableUserWithNamedAddress::forceCreate([
            'email' => 'taylor@hypervel.com',
            'name' => 'Taylor Otwell',
        ]);

        $user->notify(new TestMailNotificationWithSubject);

        $message = $this->sentMessage();

        $this->assertSame([
            ['taylor@hypervel.com', 'Taylor Otwell'],
            ['foo_taylor@hypervel.com', ''],
        ], $this->addresses($message->getTo()));
        $this->assertSame('mail custom subject', $message->getSubject());
    }

    public function testMailIsSentWithSubject(): void
    {
        $notification = new TestMailNotificationWithSubject;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage();

        $this->assertSame([['taylor@hypervel.com', '']], $this->addresses($message->getTo()));
        $this->assertSame('mail custom subject', $message->getSubject());
    }

    public function testMailIsSentToMultipleAddresses(): void
    {
        $notification = new TestMailNotificationWithSubject;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUserWithMultipleAddresses::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage();

        $this->assertSame([
            ['foo_taylor@hypervel.com', ''],
            ['bar_taylor@hypervel.com', ''],
        ], $this->addresses($message->getTo()));
        $this->assertSame('mail custom subject', $message->getSubject());
    }

    public function testMailIsSentUsingMailable(): void
    {
        $notification = new TestMailNotificationWithMailable;

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage();

        $this->assertSame([['taylor@hypervel.com', '']], $this->addresses($message->getTo()));
        $this->assertSame('Mailable Subject', $message->getSubject());
        $this->assertStringContainsString('mailable body', $message->getHtmlBody());
    }

    public function testMailIsSentUsingMailMessageWithHtmlAndPlain(): void
    {
        $notification = new TestMailNotificationWithHtmlAndPlain;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage();

        $this->assertSame([['taylor@hypervel.com', '']], $this->addresses($message->getTo()));
        $this->assertSame('Test Mail Notification With Html And Plain', $message->getSubject());
        $this->assertSame('htmlContent', trim($message->getHtmlBody()));
        $this->assertSame('plainContent', trim($message->getTextBody()));
    }

    public function testMailIsSentUsingMailMessageWithHtmlOnly(): void
    {
        $notification = new TestMailNotificationWithHtmlOnly;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage();

        $this->assertSame([['taylor@hypervel.com', '']], $this->addresses($message->getTo()));
        $this->assertSame('Test Mail Notification With Html Only', $message->getSubject());
        $this->assertSame('htmlContent', trim($message->getHtmlBody()));
        $this->assertNull($message->getTextBody());
    }

    public function testMailIsSentUsingMailMessageWithPlainOnly(): void
    {
        $notification = new TestMailNotificationWithPlainOnly;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);

        $message = $this->sentMessage();

        $this->assertSame([['taylor@hypervel.com', '']], $this->addresses($message->getTo()));
        $this->assertSame('Test Mail Notification With Plain Only', $message->getSubject());
        $this->assertSame('plainContent', trim($message->getTextBody()));
        $this->assertNull($message->getHtmlBody());
    }

    /**
     * Capture the data of the next message being sent.
     */
    private function captureSendingData(): object
    {
        $captured = new class {
            public array $data = [];
        };

        Event::listen(MessageSending::class, function (MessageSending $event) use ($captured): void {
            $captured->data = $event->data;
        });

        return $captured;
    }

    /**
     * Get the only message sent through the given array mailer.
     */
    private function sentMessage(string $mailer = 'array'): Email
    {
        $messages = $this->app->make('mail.manager')->mailer($mailer)->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);

        return $messages->first()->getOriginalMessage();
    }

    /**
     * Get the email and name of each address.
     *
     * @param Address[] $addresses
     */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): array => [$address->getAddress(), $address->getName()], $addresses);
    }
}

class NotifiableUserWithNamedAddress extends NotifiableUser
{
    /**
     * Route the mail notification to named addresses.
     */
    public function routeNotificationForMail(Notification $notification): array
    {
        return [
            $this->email => $this->name,
            'foo_' . $this->email,
        ];
    }
}

class NotifiableUserWithMultipleAddresses extends NotifiableUser
{
    /**
     * Route the mail notification to multiple addresses.
     */
    public function routeNotificationForMail(Notification $notification): array
    {
        return [
            'foo_' . $this->email,
            'bar_' . $this->email,
        ];
    }
}

class TestMailNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): MailMessage
    {
        return (new MailMessage)
            ->priority(1)
            ->cc('cc@deepblue.com', 'cc')
            ->bcc('bcc@deepblue.com', 'bcc')
            ->from('jack@deepblue.com', 'Jacques Mayol')
            ->replyTo('jack@deepblue.com', 'Jacques Mayol')
            ->line('The introduction to the notification.')
            ->mailer('foo');
    }
}

class TestMailNotificationWithSubject extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('mail custom subject')
            ->line('The introduction to the notification.');
    }
}

class TestMailNotificationWithMailable extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): Mailable
    {
        return new TestNotificationMailable;
    }
}

class TestNotificationMailable extends Mailable
{
    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(to: ['taylor@hypervel.com'], subject: 'Mailable Subject');
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(htmlString: 'mailable body');
    }
}

class TestMailNotificationWithHtmlAndPlain extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): MailMessage
    {
        return (new MailMessage)
            ->view(['html', 'plain']);
    }
}

class TestMailNotificationWithHtmlOnly extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): MailMessage
    {
        return (new MailMessage)
            ->view('html');
    }
}

class TestMailNotificationWithPlainOnly extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): MailMessage
    {
        return (new MailMessage)
            ->view([null, 'plain']);
    }
}

class TestMailNotificationWithCustomTheme extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(NotifiableUser $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(NotifiableUser $notifiable): MailMessage
    {
        return (new MailMessage)
            ->priority(1)
            ->cc('cc@deepblue.com', 'cc')
            ->bcc('bcc@deepblue.com', 'bcc')
            ->from('jack@deepblue.com', 'Jacques Mayol')
            ->replyTo('jack@deepblue.com', 'Jacques Mayol')
            ->line('The introduction to the notification.')
            ->theme('my-custom-theme')
            ->mailer('foo');
    }
}
