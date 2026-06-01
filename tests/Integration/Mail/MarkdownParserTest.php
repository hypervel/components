<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Mail\Markdown;
use Hypervel\Support\EncodedHtmlString;
use Hypervel\Support\HtmlString;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testItCanParseMarkdownWithCustomExtensionsViaConfig(): void
    {
        $this->configureMarkdownExtensions([
            \League\CommonMark\Extension\Strikethrough\StrikethroughExtension::class,
        ]);

        tap(Markdown::parse('~~strikethrough text~~'), function ($html) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $expected = '<p><del>strikethrough text</del></p>';

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
            $this->assertSame((string) $html, (string) $html->toHtml());
        });
    }

    public function testItCanParseMarkdownWithoutCustomExtensionsDoesNotApplyThem(): void
    {
        $this->configureMarkdownExtensions([]);

        tap(Markdown::parse('~~strikethrough text~~'), function ($html) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $expected = '<p>~~strikethrough text~~</p>';

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
            $this->assertSame((string) $html, (string) $html->toHtml());
        });
    }

    public function testItCanParseMarkdownWithMultipleCustomExtensions(): void
    {
        $this->configureMarkdownExtensions([
            \League\CommonMark\Extension\Strikethrough\StrikethroughExtension::class,
            \League\CommonMark\Extension\TaskList\TaskListExtension::class,
        ]);

        tap(Markdown::parse('~~strikethrough~~'), function ($html) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $expected = '<p><del>strikethrough</del></p>';

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
            $this->assertSame((string) $html, (string) $html->toHtml());
        });

        tap(Markdown::parse('- [ ] Task item'), function ($html) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $expected = "<ul>\n<li><input disabled=\"\" type=\"checkbox\"> Task item</li>\n</ul>";

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
            $this->assertSame((string) $html, (string) $html->toHtml());
        });
    }

    public function testItCanParseMarkdownEncodedStringWithCustomExtensions(): void
    {
        $this->configureMarkdownExtensions([
            \League\CommonMark\Extension\Strikethrough\StrikethroughExtension::class,
        ]);

        tap(Markdown::parse(new EncodedHtmlString('~~strikethrough text~~'), encoded: true), function ($html) {
            $this->assertInstanceOf(HtmlString::class, $html);

            $expected = '<p><del>strikethrough text</del></p>';

            $this->assertStringEqualsStringIgnoringLineEndings($expected . PHP_EOL, (string) $html);
        });
    }

    /**
     * @param array<int, class-string<\League\CommonMark\Extension\ExtensionInterface>> $extensions
     */
    protected function configureMarkdownExtensions(array $extensions): void
    {
        $this->app->make('config')->set('mail.markdown.extensions', $extensions);

        $this->app->forgetInstance(Markdown::class);
        $this->app->make(Markdown::class);
    }
}
