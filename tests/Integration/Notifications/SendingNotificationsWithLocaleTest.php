<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Translation\HasLocalePreference;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Events\LocaleUpdated;
use Hypervel\Mail\Mailable;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Notification as NotificationFacade;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Assert;

#[WithConfig('database.default', 'testing')]
class SendingNotificationsWithLocaleTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('mail.default', 'array');
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('app.locale', 'en');

        $app->make('view')->addLocation(__DIR__ . '/Fixtures');

        $app->make('translator')->setLoaded([
            '*' => [
                '*' => [
                    'en' => ['hi' => 'hello'],
                    'es' => ['hi' => 'hola'],
                    'fr' => ['hi' => 'bonjour'],
                ],
            ],
        ]);
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

    public function testMailIsSentWithDefaultLocale(): void
    {
        $user = NotifiableLocalizedUser::forceCreate([
            'email' => 'taylor@hypervel.com',
            'name' => 'Taylor Otwell',
        ]);

        NotificationFacade::send($user, new GreetingMailNotification);

        $this->assertStringContainsString(
            'hello',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testMailIsSentWithFacadeSelectedLocale(): void
    {
        $user = NotifiableLocalizedUser::forceCreate([
            'email' => 'taylor@hypervel.com',
            'name' => 'Taylor Otwell',
        ]);

        NotificationFacade::locale('fr')->send($user, new GreetingMailNotification);

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testMailIsSentWithNotificationSelectedLocale(): void
    {
        $users = [
            NotifiableLocalizedUser::forceCreate([
                'email' => 'taylor@hypervel.com',
                'name' => 'Taylor Otwell',
            ]),
            NotifiableLocalizedUser::forceCreate([
                'email' => 'mohamed@hypervel.com',
                'name' => 'Mohamed Said',
            ]),
        ];

        NotificationFacade::send($users, (new GreetingMailNotification)->locale('fr'));

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[1]->toString()
        );
    }

    public function testMailableIsSentWithSelectedLocale(): void
    {
        $user = NotifiableLocalizedUser::forceCreate([
            'email' => 'taylor@hypervel.com',
            'name' => 'Taylor Otwell',
        ]);

        NotificationFacade::locale('fr')->send($user, new GreetingMailNotificationWithMailable);

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testMailIsSentWithLocaleUpdatedListenersCalled(): void
    {
        CarbonImmutable::setTestNow('2018-07-25');

        Event::listen(LocaleUpdated::class, function (LocaleUpdated $event): void {
            CarbonImmutable::setLocale($event->locale);
        });

        $user = NotifiableLocalizedUser::forceCreate([
            'email' => 'taylor@hypervel.com',
            'name' => 'Taylor Otwell',
        ]);

        $user->notify((new GreetingMailNotification)->locale('fr'));

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );

        Assert::assertMatchesRegularExpression(
            '/dans (1|un) jour/',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );

        $this->assertTrue($this->app->isLocale('en'));

        $this->assertSame('en', CarbonImmutable::getLocale());
    }

    public function testLocaleIsSentWithNotifiablePreferredLocale(): void
    {
        $recipient = new NotifiableEmailLocalePreferredUser([
            'email' => 'test@mail.com',
            'email_locale' => 'fr',
        ]);

        $recipient->notify(new GreetingMailNotification);

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testLocaleIsSentWithNotifiablePreferredLocaleForMultipleRecipients(): void
    {
        $recipients = [
            new NotifiableEmailLocalePreferredUser([
                'email' => 'test@mail.com',
                'email_locale' => 'fr',
            ]),
            new NotifiableEmailLocalePreferredUser([
                'email' => 'test.2@mail.com',
                'email_locale' => 'es',
            ]),
            NotifiableLocalizedUser::forceCreate([
                'email' => 'test.3@mail.com',
            ]),
        ];

        NotificationFacade::send(
            $recipients,
            new GreetingMailNotification
        );

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
        $this->assertStringContainsString(
            'hola',
            app('mailer')->getSymfonyTransport()->messages()[1]->toString()
        );
        $this->assertStringContainsString(
            'hello',
            app('mailer')->getSymfonyTransport()->messages()[2]->toString()
        );
    }

    public function testLocaleIsSentWithNotificationSelectedLocaleOverridingNotifiablePreferredLocale(): void
    {
        $recipient = new NotifiableEmailLocalePreferredUser([
            'email' => 'test@mail.com',
            'email_locale' => 'es',
        ]);

        $recipient->notify(
            (new GreetingMailNotification)->locale('fr')
        );

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testLocaleIsSentWithFacadeSelectedLocaleOverridingNotifiablePreferredLocale(): void
    {
        $recipient = new NotifiableEmailLocalePreferredUser([
            'email' => 'test@mail.com',
            'email_locale' => 'es',
        ]);

        NotificationFacade::locale('fr')->send(
            $recipient,
            new GreetingMailNotification
        );

        $this->assertStringContainsString(
            'bonjour',
            app('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }
}

class NotifiableLocalizedUser extends Model
{
    use Notifiable;

    protected ?string $table = 'users';

    public bool $timestamps = false;
}

class NotifiableEmailLocalePreferredUser extends Model implements HasLocalePreference
{
    use Notifiable;

    protected array $fillable = [
        'email',
        'email_locale',
    ];

    /**
     * Get the preferred locale.
     */
    public function preferredLocale(): ?string
    {
        return $this->email_locale;
    }
}

class GreetingMailNotification extends Notification
{
    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->greeting(__('hi'))
            ->line(CarbonImmutable::tomorrow()->diffForHumans());
    }
}

class GreetingMailNotificationWithMailable extends Notification
{
    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): array
    {
        return [MailChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): GreetingMailable
    {
        return (new GreetingMailable)
            ->to($notifiable->email);
    }
}

class GreetingMailable extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('greeting');
    }
}
