<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Markdown;
use Hypervel\Support\EncodedHtmlString;
use Hypervel\Support\HtmlString;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class MarkdownParserTest extends TestCase
{
    protected function tearDown(): void
    {
        Markdown::flushState();
        EncodedHtmlString::flushState();

        parent::tearDown();
    }

    #[DataProvider('markdownDataProvider')]
    public function testItCanParseMarkdownString($given, $expected)
    {
        tap(Markdown::parse($given), function ($html) use ($expected) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
            $this->assertSame((string) $html, (string) $html->toHtml());
        });
    }

    public static function markdownDataProvider()
    {
        yield ['[Hypervel](https://hypervel.org)', '<p><a href="https://hypervel.org">Hypervel</a></p>'];
        yield ['\[Hypervel](https://hypervel.org)', '<p>[Hypervel](https://hypervel.org)</p>'];
        yield ['![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)', '<p><img src="https://hypervel.org/assets/img/welcome/background.svg" alt="Welcome to Hypervel" /></p>'];
        yield ['!\[Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)', '<p>![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)</p>'];
        yield ['Visit https://hypervel.org/docs to browse the documentation', '<p>Visit https://hypervel.org/docs to browse the documentation</p>'];
        yield ['Visit <https://hypervel.org/docs> to browse the documentation', '<p>Visit <a href="https://hypervel.org/docs">https://hypervel.org/docs</a> to browse the documentation</p>'];
        yield ['Visit <span>https://hypervel.org/docs</span> to browse the documentation', '<p>Visit <span>https://hypervel.org/docs</span> to browse the documentation</p>'];
    }

    #[DataProvider('markdownEncodedDataProvider')]
    public function testItCanParseMarkdownEncodedString($given, $expected)
    {
        tap(Markdown::parse($given, encoded: true), function ($html) use ($expected) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
        });
    }

    public static function markdownEncodedDataProvider()
    {
        yield [new EncodedHtmlString('[Hypervel](https://hypervel.org)'), '<p>[Hypervel](https://hypervel.org)</p>'];

        yield [
            new EncodedHtmlString('![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)'),
            '<p>![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)</p>',
        ];

        yield [
            new EncodedHtmlString('Visit https://hypervel.org/docs to browse the documentation'),
            '<p>Visit https://hypervel.org/docs to browse the documentation</p>',
        ];

        yield [
            new EncodedHtmlString('Visit <https://hypervel.org/docs> to browse the documentation'),
            '<p>Visit &lt;https://hypervel.org/docs&gt; to browse the documentation</p>',
        ];

        yield [
            new EncodedHtmlString('Visit <span>https://hypervel.org/docs</span> to browse the documentation'),
            '<p>Visit &lt;span&gt;https://hypervel.org/docs&lt;/span&gt; to browse the documentation</p>',
        ];

        yield [
            new EncodedHtmlString(new HtmlString('Visit <span>https://hypervel.org/docs</span> to browse the documentation')),
            '<p>Visit <span>https://hypervel.org/docs</span> to browse the documentation</p>',
        ];

        yield [
            '![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)<br />' . new EncodedHtmlString('Visit <span>https://hypervel.org/docs</span> to browse the documentation'),
            '<p><img src="https://hypervel.org/assets/img/welcome/background.svg" alt="Welcome to Hypervel" /><br />Visit &lt;span&gt;https://hypervel.org/docs&lt;/span&gt; to browse the documentation</p>',
        ];
    }
}
