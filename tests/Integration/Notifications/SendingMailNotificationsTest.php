<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Mail\Factory as MailFactory;
use Hypervel\Contracts\Mail\Mailable;
use Hypervel\Contracts\Mail\Mailer;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Mail\Markdown;
use Hypervel\Mail\Message;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Notifications\Fixtures\Models\NotifiableUser;
use Mockery as m;

#[WithConfig('database.default', 'testing')]
class SendingMailNotificationsTest extends TestCase
{
    public MailFactory $mailFactory;

    public Mailer $mailer;

    public Markdown $markdown;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->mailFactory = m::mock(MailFactory::class);
        $this->mailer = m::mock(Mailer::class);
        $this->mailFactory->shouldReceive('mailer')->andReturn($this->mailer);
        $this->markdown = m::mock(Markdown::class);

        $app->extend(Markdown::class, function (): Markdown {
            return $this->markdown;
        });

        $app->extend(Mailer::class, function (): Mailer {
            return $this->mailer;
        });

        $app->extend(MailFactory::class, function (): MailFactory {
            return $this->mailFactory;
        });

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

        $this->markdown->expects('render')
            ->withArgs(fn (mixed ...$args): bool => ($args['theme'] ?? $args[3] ?? null) === 'default')
            ->andReturn(new HtmlString('htmlContent'));
        $this->markdown->expects('renderText')->andReturn(new HtmlString('textContent'));

        $this->setMailerSendAssertions($notification, $user, function (Closure $closure): bool {
            $message = m::mock(Message::class);

            $message->expects('to')->with(['taylor@hypervel.com']);

            $message->expects('cc')->with('cc@deepblue.com', 'cc');

            $message->expects('bcc')->with('bcc@deepblue.com', 'bcc');

            $message->expects('from')->with('jack@deepblue.com', 'Jacques Mayol');

            $message->expects('replyTo')->with('jack@deepblue.com', 'Jacques Mayol');

            $message->expects('subject')->with('Test Mail Notification');

            $message->expects('priority')->with(1);

            $closure($message);

            return true;
        });

        $user->notify($notification);
    }

    public function testMailIsSentWithCustomTheme(): void
    {
        $notification = new TestMailNotificationWithCustomTheme;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $this->markdown->expects('render')
            ->withArgs(fn (mixed ...$args): bool => ($args['theme'] ?? $args[3] ?? null) === 'my-custom-theme')
            ->andReturn(new HtmlString('htmlContent'));
        $this->markdown->expects('renderText')->andReturn(new HtmlString('textContent'));

        $this->setMailerSendAssertions($notification, $user, function (Closure $closure): bool {
            $message = m::mock(Message::class);

            $message->expects('to')->with(['taylor@hypervel.com']);

            $message->expects('cc')->with('cc@deepblue.com', 'cc');

            $message->expects('bcc')->with('bcc@deepblue.com', 'bcc');

            $message->expects('from')->with('jack@deepblue.com', 'Jacques Mayol');

            $message->expects('replyTo')->with('jack@deepblue.com', 'Jacques Mayol');

            $message->expects('subject')->with('Test Mail Notification With Custom Theme');

            $message->expects('priority')->with(1);

            $closure($message);

            return true;
        });

        $user->notify($notification);
    }

    /**
     * Expect the notification view, data, and message callback.
     */
    private function setMailerSendAssertions(
        Notification $notification,
        NotifiableUser $user,
        callable $callbackExpectationClosure
    ): void {
        $this->mailer->expects('send')->withArgs(function (mixed ...$args) use ($notification, $user, $callbackExpectationClosure): bool {
            $viewArray = $args[0];

            if (! m::on(fn (Closure $closure): bool => (string) $closure([]) === 'htmlContent')->match($viewArray['html'])) {
                return false;
            }

            if (! m::on(fn (Closure $closure): bool => (string) $closure([]) === 'textContent')->match($viewArray['text'])) {
                return false;
            }

            $data = $args[1];

            $expected = array_merge($notification->toMail($user)->toArray(), [
                '__hypervel_notification_id' => $notification->id,
                '__hypervel_notification' => get_class($notification),
                '__hypervel_notification_queued' => false,
            ]);

            if (array_keys($data) !== array_keys($expected)) {
                return false;
            }
            if (array_values($data) !== array_values($expected)) {
                return false;
            }

            return m::on($callbackExpectationClosure)->match($args[2]);
        });
    }

    public function testMailIsSentToNamedAddress(): void
    {
        $notification = new TestMailNotification;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUserWithNamedAddress::forceCreate([
            'email' => 'taylor@hypervel.com',
            'name' => 'Taylor Otwell',
        ]);

        $this->markdown->expects('render')
            ->withArgs(fn (mixed ...$args): bool => ($args['theme'] ?? $args[3] ?? null) === 'default')
            ->andReturn(new HtmlString('htmlContent'));
        $this->markdown->expects('renderText')->andReturn(new HtmlString('textContent'));

        $this->setMailerSendAssertions($notification, $user, function (Closure $closure): bool {
            $message = m::mock(Message::class);

            $message->expects('to')->with(['taylor@hypervel.com' => 'Taylor Otwell', 'foo_taylor@hypervel.com']);

            $message->expects('cc')->with('cc@deepblue.com', 'cc');

            $message->expects('bcc')->with('bcc@deepblue.com', 'bcc');

            $message->expects('from')->with('jack@deepblue.com', 'Jacques Mayol');

            $message->expects('replyTo')->with('jack@deepblue.com', 'Jacques Mayol');

            $message->expects('subject')->with('Test Mail Notification');

            $message->expects('priority')->with(1);

            $closure($message);

            return true;
        });

        $user->notify($notification);
    }

    public function testMailIsSentWithSubject(): void
    {
        $notification = new TestMailNotificationWithSubject;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $this->markdown->expects('render')
            ->withArgs(fn (mixed ...$args): bool => ($args['theme'] ?? $args[3] ?? null) === 'default')
            ->andReturn(new HtmlString('htmlContent'));
        $this->markdown->expects('renderText')->andReturn(new HtmlString('textContent'));

        $this->setMailerSendAssertions($notification, $user, function (Closure $closure): bool {
            $message = m::mock(Message::class);

            $message->expects('to')->with(['taylor@hypervel.com']);

            $message->expects('subject')->with('mail custom subject');

            $closure($message);

            return true;
        });

        $user->notify($notification);
    }

    public function testMailIsSentToMultipleAddresses(): void
    {
        $notification = new TestMailNotificationWithSubject;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUserWithMultipleAddresses::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $this->markdown->expects('render')
            ->withArgs(fn (mixed ...$args): bool => ($args['theme'] ?? $args[3] ?? null) === 'default')
            ->andReturn(new HtmlString('htmlContent'));
        $this->markdown->expects('renderText')->andReturn(new HtmlString('textContent'));

        $this->setMailerSendAssertions($notification, $user, function (Closure $closure): bool {
            $message = m::mock(Message::class);

            $message->expects('to')->with(['foo_taylor@hypervel.com', 'bar_taylor@hypervel.com']);

            $message->expects('subject')->with('mail custom subject');

            $closure($message);

            return true;
        });

        $user->notify($notification);
    }

    public function testMailIsSentUsingMailable(): void
    {
        $notification = new TestMailNotificationWithMailable;

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $user->notify($notification);
    }

    public function testMailIsSentUsingMailMessageWithHtmlAndPlain(): void
    {
        $notification = new TestMailNotificationWithHtmlAndPlain;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $this->mailer->expects('send')->with(
            ['html', 'plain'],
            array_merge($notification->toMail($user)->toArray(), [
                '__hypervel_notification_id' => $notification->id,
                '__hypervel_notification' => get_class($notification),
                '__hypervel_notification_queued' => false,
            ]),
            m::on(function (Closure $closure): bool {
                $message = m::mock(Message::class);

                $message->expects('to')->with(['taylor@hypervel.com']);

                $message->expects('subject')->with('Test Mail Notification With Html And Plain');

                $closure($message);

                return true;
            })
        );

        $user->notify($notification);
    }

    public function testMailIsSentUsingMailMessageWithHtmlOnly(): void
    {
        $notification = new TestMailNotificationWithHtmlOnly;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $this->mailer->expects('send')->with(
            'html',
            array_merge($notification->toMail($user)->toArray(), [
                '__hypervel_notification_id' => $notification->id,
                '__hypervel_notification' => get_class($notification),
                '__hypervel_notification_queued' => false,
            ]),
            m::on(function (Closure $closure): bool {
                $message = m::mock(Message::class);

                $message->expects('to')->with(['taylor@hypervel.com']);

                $message->expects('subject')->with('Test Mail Notification With Html Only');

                $closure($message);

                return true;
            })
        );

        $user->notify($notification);
    }

    public function testMailIsSentUsingMailMessageWithPlainOnly(): void
    {
        $notification = new TestMailNotificationWithPlainOnly;
        $notification->id = Str::uuid()->toString();

        $user = NotifiableUser::forceCreate([
            'email' => 'taylor@hypervel.com',
        ]);

        $this->mailer->expects('send')->with(
            [null, 'plain'],
            array_merge($notification->toMail($user)->toArray(), [
                '__hypervel_notification_id' => $notification->id,
                '__hypervel_notification' => get_class($notification),
                '__hypervel_notification_queued' => false,
            ]),
            m::on(function (Closure $closure): bool {
                $message = m::mock(Message::class);

                $message->expects('to')->with(['taylor@hypervel.com']);

                $message->expects('subject')->with('Test Mail Notification With Plain Only');

                $closure($message);

                return true;
            })
        );

        $user->notify($notification);
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
        $mailable = m::mock(Mailable::class);

        $mailable->expects('send');

        return $mailable;
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
