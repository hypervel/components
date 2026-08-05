<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Translation\HasLocalePreference;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Events\LocaleUpdated;
use Hypervel\Mail\Mailable;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Mail;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Assert;

class SendingMailWithLocaleTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('mail', [
            'default' => 'array',
            'from' => ['address' => 'hello@hypervel.org', 'name' => 'Hypervel'],
            'mailers' => [
                'array' => ['transport' => 'array'],
            ],
        ]);

        $app->make('config')->set('app.locale', 'en');

        $app->make('view')->addLocation(__DIR__ . '/Fixtures');

        $app->make('translator')->setLoaded([
            '*' => [
                '*' => [
                    'en' => ['nom' => 'name'],
                    'ar' => ['nom' => 'esm'],
                    'es' => ['nom' => 'nombre'],
                ],
            ],
        ]);
    }

    public function testMailIsSentWithDefaultLocale(): void
    {
        Mail::to('test@mail.com')->send(new SendingLocaleTestMail);

        $this->assertStringContainsString(
            'name',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testMailIsSentWithSelectedLocale(): void
    {
        Mail::to('test@mail.com')->locale('ar')->send(new SendingLocaleTestMail);

        $this->assertStringContainsString(
            'esm',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testMailIsSentWithLocaleFromMailable(): void
    {
        $mailable = new SendingLocaleTestMail;
        $mailable->locale('ar');

        Mail::to('test@mail.com')->send($mailable);

        $this->assertStringContainsString(
            'esm',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testMailIsSentWithLocaleUpdatedListenersCalled(): void
    {
        CarbonImmutable::setTestNow('2018-04-01');

        Event::listen(LocaleUpdated::class, function (LocaleUpdated $event): void {
            CarbonImmutable::setLocale($event->locale);
        });

        Mail::to('test@mail.com')->locale('es')->send(new SendingLocaleTimestampTestMail);

        Assert::assertMatchesRegularExpression(
            '/nombre (en|dentro de) (un|1) d=C3=ADa/',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );

        $this->assertSame('en', CarbonImmutable::getLocale());

        CarbonImmutable::setTestNow(null);
    }

    public function testLocaleIsSentWithModelPreferredLocale(): void
    {
        $recipient = new SendingLocaleTestEmailLocaleUser([
            'email' => 'test@mail.com',
            'email_locale' => 'ar',
        ]);

        Mail::to($recipient)->send(new SendingLocaleTestMail);

        $this->assertStringContainsString(
            'esm',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );

        $mailable = new Mailable;
        $mailable->to($recipient);

        $this->assertSame($recipient->email_locale, $mailable->locale);
    }

    public function testLocaleIsSentWithSelectedLocaleOverridingModelPreferredLocale(): void
    {
        $recipient = new SendingLocaleTestEmailLocaleUser([
            'email' => 'test@mail.com',
            'email_locale' => 'en',
        ]);

        Mail::to($recipient)->locale('ar')->send(new SendingLocaleTestMail);

        $this->assertStringContainsString(
            'esm',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testLocaleIsSentWithModelPreferredLocaleWillIgnorePreferredLocaleOfTheCcRecipient(): void
    {
        $toRecipient = new SendingLocaleTestEmailLocaleUser([
            'email' => 'test@mail.com',
            'email_locale' => 'ar',
        ]);

        $ccRecipient = new SendingLocaleTestEmailLocaleUser([
            'email' => 'test.cc@mail.com',
            'email_locale' => 'en',
        ]);

        Mail::to($toRecipient)->cc($ccRecipient)->send(new SendingLocaleTestMail);

        $this->assertStringContainsString(
            'esm',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testLocaleIsNotSentWithModelPreferredLocaleWhenThereAreMultipleRecipients(): void
    {
        $recipients = [
            new SendingLocaleTestEmailLocaleUser([
                'email' => 'test@mail.com',
                'email_locale' => 'ar',
            ]),
            new SendingLocaleTestEmailLocaleUser([
                'email' => 'test.2@mail.com',
                'email_locale' => 'ar',
            ]),
        ];

        Mail::to($recipients)->send(new SendingLocaleTestMail);

        $this->assertStringContainsString(
            'name',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );
    }

    public function testLocaleIsSetBackToDefaultAfterMailSent(): void
    {
        Mail::to('test@mail.com')->locale('ar')->send(new SendingLocaleTestMail);
        Mail::to('test@mail.com')->send(new SendingLocaleTestMail);

        $this->assertSame('en', $this->app->make('translator')->getLocale());

        $this->assertStringContainsString(
            'esm',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->toString()
        );

        $this->assertStringContainsString(
            'name',
            $this->app->make('mailer')->getSymfonyTransport()->messages()[1]->toString()
        );
    }
}

class SendingLocaleTestMail extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('view');
    }
}

class SendingLocaleTestEmailLocaleUser extends Model implements HasLocalePreference
{
    protected array $fillable = [
        'email',
        'email_locale',
    ];

    public function preferredLocale(): string
    {
        return $this->email_locale;
    }
}

class SendingLocaleTimestampTestMail extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('timestamp');
    }
}
