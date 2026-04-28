<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Mailable;
use Hypervel\Mail\Mailables\Content;
use Hypervel\Mail\Mailables\Envelope;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

abstract class MailableTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['view']->addLocation(__DIR__ . '/Fixtures');
    }

    #[DataProvider('markdownEncodedDataProvider')]
    public function testItCanAssertMarkdownEncodedString($given, $expected)
    {
        $mailable = new class($given) extends Mailable {
            public function __construct(public string $message)
            {
            }

            public function envelope()
            {
                return new Envelope(
                    subject: 'My basic title',
                );
            }

            public function content()
            {
                return new Content(
                    markdown: 'message',
                );
            }
        };

        $mailable->assertSeeInHtml($expected, false);
    }

    public static function markdownEncodedDataProvider()
    {
        yield ['[Hypervel](https://hypervel.org)', 'My message is: [Hypervel](https://hypervel.org)'];

        yield [
            '![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)',
            'My message is: ![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)',
        ];

        yield [
            'Visit https://hypervel.org/docs to browse the documentation',
            'My message is: Visit https://hypervel.org/docs to browse the documentation',
        ];

        yield [
            'Visit <https://hypervel.org/docs> to browse the documentation',
            'My message is: Visit &lt;https://hypervel.org/docs&gt; to browse the documentation',
        ];

        yield [
            'Visit <span>https://hypervel.org/docs</span> to browse the documentation',
            'My message is: Visit &lt;span&gt;https://hypervel.org/docs&lt;/span&gt; to browse the documentation',
        ];
    }
}
