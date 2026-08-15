<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Mail\Mailable;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\MailServiceProvider;
use Hypervel\Mail\Markdown;
use Hypervel\Support\Facades\Mail;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use ReflectionProperty;

class MailServiceProviderTest extends TestCase
{
    public function testReloadConfigurationRebuildsMailersAndMarkdownFromCurrentConfiguration(): void
    {
        config([
            'mail.default' => 'first',
            'mail.mailers.first' => ['transport' => 'array'],
            'mail.mailers.second' => ['transport' => 'array'],
            'mail.markdown.theme' => 'old',
            'mail.markdown.paths' => [],
            'mail.markdown.extensions' => [],
        ]);
        $manager = $this->app->make('mail.manager');
        $mailer = $this->app->make('mailer');
        $markdown = $this->app->make(Markdown::class);

        config([
            'mail.default' => 'second',
            'mail.markdown.theme' => 'new',
        ]);
        $this->app->getProvider(MailServiceProvider::class)->reloadConfiguration();

        $refreshedMailer = $this->app->make('mailer');
        $refreshedMarkdown = $this->app->make(Markdown::class);
        $this->assertSame($manager, $this->app->make(MailManager::class));
        $this->assertNotSame($mailer, $refreshedMailer);
        $this->assertSame($refreshedMailer, $manager->mailer('second'));
        $this->assertNotSame($markdown, $refreshedMarkdown);
        $this->assertSame('new', (new ReflectionProperty(Markdown::class, 'theme'))->getValue($refreshedMarkdown));
    }

    public function testReloadConfigurationPreservesMailFakeAndRefreshesItsWrappedManager(): void
    {
        config([
            'mail.default' => 'first',
            'mail.mailers.first' => ['transport' => 'array'],
        ]);
        $manager = $this->app->make('mail.manager');
        $mailer = $manager->mailer('first');
        $fake = Mail::fake();
        $mailable = new Mailable;
        $fake->send($mailable);

        $this->app->getProvider(MailServiceProvider::class)->reloadConfiguration();

        $this->assertSame($fake, Mail::getFacadeRoot());
        $this->assertSame([$mailable], $fake->sent(Mailable::class)->all());
        $this->assertNotSame($mailer, $manager->mailer('first'));
    }

    public function testMarkdownConfigurationRequiresEveryDeclaredMember(): void
    {
        config(['mail.markdown' => [
            'theme' => 'default',
            'paths' => [],
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [mail.markdown.extensions]');

        $this->app->make(Markdown::class);
    }
}
