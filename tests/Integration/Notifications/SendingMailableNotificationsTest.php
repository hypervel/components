<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Notifications;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;

class SendingMailableNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('mail.default', 'array');
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('app.locale', 'en');
        $config->set('mail.markdown.theme', 'blank');

        $app->make('view')->addLocation(__DIR__ . '/Fixtures');
    }

    protected function afterRefreshingDatabase()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name')->nullable();
        });
    }

    protected function beforeRefreshingDatabase()
    {
        Schema::dropIfExists('users');
    }

    public function testMarkdownNotification(): void
    {
        $user = MailableNotificationUser::forceCreate([
            'email' => 'nuno@laravel.com',
        ]);

        $user->notify(new MarkdownNotification);

        $message = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $email = $message->toString();
        $textBody = $message->getTextBody();

        $this->assertCount(1, $attachments = $message->getAttachments());
        $this->assertStringContainsString('Embed content: ', $textBody);
        $this->assertStringNotContainsString('Embed content: cid:', $textBody);

        $this->assertSame(1, preg_match('/Embed content: cid:([^<\s]+)/', $message->getHtmlBody(), $matches));

        $filename = explode(' file: ', (new Stringable($textBody))->explode("\n")
            ->filter(fn ($line) => str_contains($line, 'Embed file: '))
            ->first())[1];

        // The HTML view is compiled with a different echo format, so its compiled filename has a suffix.
        $hash = explode('.', $filename)[0];

        $this->assertStringContainsString('Content-Type: application/x-php;', $email);
        $this->assertStringContainsString($hash, $email);
        $this->assertStringContainsString("Content-ID: <{$matches[1]}>", $email);

        // Inspect the parsed attachment because its longer filename may be folded in MIME headers.
        $this->assertSame(1, preg_match('/Embed file: ([^<\s]+)/', $message->getHtmlBody(), $htmlFilename));
        $this->assertSame($htmlFilename[1], $attachments[0]->getFilename());
        $this->assertSame('inline', $attachments[0]->getDisposition());
        $this->assertSame('base64', $attachments[0]->getPreparedHeaders()->get('Content-Transfer-Encoding')->getBodyAsString());
    }

    public function testCanSetTheme(): void
    {
        $user = MailableNotificationUser::forceCreate([
            'email' => 'nuno@laravel.com',
        ]);

        $user->notify(new MarkdownNotification('color-test'));
        $mailTransport = app('mailer')->getSymfonyTransport();

        $contents = $mailTransport->messages()[0]->getOriginalMessage()->toString();
        $this->assertStringContainsString('<body style=3D"color: test;">', $contents);

        // confirm passing no theme resets to the app's default theme
        $user->notify(new MarkdownNotification);

        $contents = $mailTransport->messages()[1]->getOriginalMessage()->toString();
        $this->assertStringNotContainsString('<body style=3D"color: test;">', $contents);
    }
}

class MailableNotificationUser extends Model
{
    use Notifiable;

    protected ?string $table = 'users';

    public bool $timestamps = false;
}

class MarkdownNotification extends Notification
{
    public function __construct(
        protected ?string $theme = null
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)->markdown('markdown');

        if (! is_null($this->theme)) {
            $message->theme($this->theme);
        }

        return $message;
    }
}
