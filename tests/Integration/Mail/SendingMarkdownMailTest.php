<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailables\Content;
use Hypervel\Mail\Mailables\Envelope;
use Hypervel\Mail\Markdown;
use Hypervel\Support\Facades\Mail;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;

class SendingMarkdownMailTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app->make('config')->set('mail', [
            'default' => 'array',
            'from' => ['address' => 'hello@hypervel.org', 'name' => 'Hypervel'],
            'mailers' => [
                'array' => ['transport' => 'array'],
            ],
        ]);

        $app['view']->addNamespace('mail', __DIR__ . '/Fixtures')
            ->addLocation(__DIR__ . '/Fixtures');
    }

    public function testMailIsSent()
    {
        $mailable = new MarkdownBasicMailable;

        $mailable
            ->assertHasSubject('My basic title')
            ->assertSeeInText('My basic content')
            ->assertSeeInHtml('My basic content');
    }

    public function testMailMayHaveSpecificTextView()
    {
        $mailable = new MarkdownBasicMailableWithTextView;

        $mailable
            ->assertHasSubject('My basic title')
            ->assertSeeInHtml('My basic content')
            ->assertSeeInText('My basic text view')
            ->assertDontSeeInText('My basic content');
    }

    public function testEmbed()
    {
        Mail::to('test@mail.com')->send($mailable = new MarkdownEmbedMailable);

        $mailable->assertSeeInHtml('Embed content: cid:');
        $mailable->assertSeeInText('Embed content: ');
        $mailable->assertDontSeeInText('Embed content: cid:');

        $email = $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->toString();

        $cid = explode(' cid:', (new Stringable($email))->explode("\r\n")
            ->filter(fn ($line) => str_contains($line, ' content: cid:'))
            ->first())[1];

        $filename = explode('Embed file: ', (new Stringable($email))->explode("\r\n")
            ->filter(fn ($line) => str_contains($line, ' file:'))
            ->first())[1];

        $this->assertStringContainsString(<<<EOT
        Content-Type: application/x-php; name={$filename}\r
        Content-Transfer-Encoding: base64\r
        Content-Disposition: inline; name={$filename};\r
         filename={$filename}\r
        Content-ID: <{$cid}>\r
        EOT, $email);
    }

    public function testEmbedData()
    {
        Mail::to('test@mail.com')->send($mailable = new MarkdownEmbedDataMailable);

        $mailable->assertSeeInText('Embed data content: ');
        $mailable->assertSeeInHtml('Embed data content: cid:');

        $email = $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->toString();

        $this->assertStringContainsString(<<<EOT
        Content-Type: image/png; name=foo.jpg\r
        Content-Transfer-Encoding: base64\r
        Content-Disposition: inline; name=foo.jpg; filename=foo.jpg\r
        EOT, $email);
    }

    public function testEmbedMultilineImage()
    {
        Mail::to('test@mail.com')->send($mailable = new MarkdownEmbedMultilineMailable);

        $html = html_entity_decode($mailable->render());

        $this->assertStringContainsString('Embed multiline content: <img', $html);
        $this->assertStringContainsString('alt="multiline image"', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString('src="cid:', $html);
    }

    public function testEmbeddedImagesAreInlinedWhenRenderingMailable()
    {
        $html = $this->app->make('mailer')->render('embed-image', [
            'image' => __DIR__ . '/Fixtures/empty_image.jpg',
        ]);

        $this->assertStringContainsString('src="data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('src="cid:', $html);
    }

    public function testMessageAsPublicPropertyMayBeDefinedAsViewData()
    {
        Mail::to('test@mail.com')->send($mailable = new MarkdownMessageAsPublicPropertyMailable);

        $mailable
            ->assertSeeInText('My message is: My message.')
            ->assertSeeInHtml('My message is: My message.');

        $email = $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->toString();

        $this->assertStringContainsString('My message is: My message.', $email);
    }

    public function testMessageAsWithNamedParameterMayBeDefinedAsViewData()
    {
        Mail::to('test@mail.com')->send($mailable = new MarkdownMessageAsWithNamedParameterMailable);

        $mailable
            ->assertSeeInText('My message is: My message.')
            ->assertSeeInHtml('My message is: My message.');

        $email = $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->toString();

        $this->assertStringContainsString('My message is: My message.', $email);
    }

    public function testTheme()
    {
        Mail::to('test@mail.com')->send(new MarkdownBasicMailable);
        $this->assertSame('default', $this->app->make(Markdown::class)->getTheme());

        Mail::to('test@mail.com')->send(new MarkdownBasicMailableWithTheme);
        $this->assertSame('taylor', $this->app->make(Markdown::class)->getTheme());

        Mail::to('test@mail.com')->send(new MarkdownBasicMailable);
        $this->assertSame('default', $this->app->make(Markdown::class)->getTheme());
    }

    public function testEmbeddedImageContentIdConsistencyAcrossMailerFailoverClones()
    {
        Mail::to('test@mail.com')->send($mailable = new MarkdownEmbedImageMailable);

        /** @var \Symfony\Component\Mime\Email $originalEmail */
        $originalEmail = $this->app->make('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $expectedContentId = $originalEmail->getAttachments()[0]->getContentId();

        // Simulate failover mailer scenario where email is cloned for retry.
        $firstClonedEmail = quoted_printable_decode((clone $originalEmail)->toString());
        [$htmlCid, $attachmentContentId] = $this->extractContentIdsFromEmail($firstClonedEmail);

        $this->assertEquals($htmlCid, $attachmentContentId, 'HTML img src CID should match attachment Content-ID header');
        $this->assertEquals($expectedContentId, $htmlCid, 'Cloned email CID should match original attachment CID');

        // Verify consistency is maintained across multiple clone operations.
        $secondClonedEmail = quoted_printable_decode((clone $originalEmail)->toString());
        [$htmlCid, $attachmentContentId] = $this->extractContentIdsFromEmail($secondClonedEmail);

        $this->assertEquals($htmlCid, $attachmentContentId, 'HTML img src CID should match attachment Content-ID header on subsequent clone');
        $this->assertEquals($expectedContentId, $htmlCid, 'Multiple clones should preserve original CID');
    }

    /**
     * Extract Content IDs from email for embedded image validation.
     *
     * @return array{0: null|string, 1: null|string}
     */
    private function extractContentIdsFromEmail(string $rawEmail): array
    {
        preg_match('/<img[^>]+src="cid:([^"]+)"/', $rawEmail, $htmlMatches);
        $htmlImageCid = $htmlMatches[1] ?? null;

        preg_match('/Content-ID:\s*<([^>]+)>/', $rawEmail, $headerMatches);
        $attachmentContentId = $headerMatches[1] ?? null;

        return [$htmlImageCid, $attachmentContentId];
    }
}

class MarkdownBasicMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'basic',
        );
    }
}

class MarkdownBasicMailableWithTheme extends Mailable
{
    public ?string $theme = 'taylor';

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'basic',
        );
    }
}

class MarkdownBasicMailableWithTextView extends Mailable
{
    public string $textView = 'text';

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'basic',
        );
    }
}

class MarkdownEmbedMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'embed',
        );
    }
}

class MarkdownEmbedMultilineMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'embed-multiline',
        );
    }
}

class MarkdownEmbedDataMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'embed-data',
        );
    }
}

class MarkdownEmbedImageMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'embed-image',
            with: [
                'image' => __DIR__ . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'empty_image.jpg',
            ]
        );
    }
}

class MarkdownMessageAsPublicPropertyMailable extends Mailable
{
    public string $message = 'My message';

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'message',
        );
    }
}

class MarkdownMessageAsWithNamedParameterMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'My basic title',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'message',
            with: [
                'message' => 'My message',
            ]
        );
    }
}
