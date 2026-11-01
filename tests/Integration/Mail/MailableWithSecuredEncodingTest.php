<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Markdown;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

class MailableWithSecuredEncodingTest extends MailableTestCase
{
    use LazilyRefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        Markdown::withSecuredEncoding();
    }

    #[WithMigration]
    #[DataProvider('markdownEncodedTemplateDataProvider')]
    public function testItCanAssertMarkdownEncodedStringUsingTemplate(string $given, string $expected): void
    {
        $user = UserFactory::new()->create([
            'name' => $given,
        ]);

        $mailable = new class($user) extends Mailable {
            public ?string $theme = 'taylor';

            public function __construct(public User $user)
            {
            }

            public function build(): static
            {
                return $this->markdown('message-with-template');
            }
        };

        $mailable->assertSeeInHtml($expected, false);
    }

    #[WithMigration]
    #[DataProvider('markdownEncodedTemplateDataProvider')]
    public function testItCanAssertMarkdownEncodedStringUsingTemplateWithTable(string $given, string $expected): void
    {
        $user = UserFactory::new()->create([
            'name' => $given,
        ]);

        $mailable = new class($user) extends Mailable {
            public ?string $theme = 'taylor';

            public function __construct(public User $user)
            {
            }

            public function build(): static
            {
                return $this->markdown('table-with-template');
            }
        };

        $mailable->assertSeeInHtml($expected, false);
        $mailable->assertSeeInHtml('<p>This is a <em>subcopy</em></p>', false);
        $mailable->assertSeeInHtml(<<<'TABLE'
<table>
<thead>
<tr>
<th>Hypervel</th>
<th align="center">Table</th>
<th align="right">Example</th>
</tr>
</thead>
<tbody>
<tr>
<td>Col 2 is</td>
<td align="center">Centered</td>
<td align="right">$10</td>
</tr>
<tr>
<td>Col 3 is</td>
<td align="center">Right-Aligned</td>
<td align="right">$20</td>
</tr>
</tbody>
</table>
TABLE, false);
    }

    public static function markdownEncodedTemplateDataProvider(): iterable
    {
        yield ['[Hypervel](https://hypervel.org)', '<em>Hi</em> [Hypervel](https://hypervel.org)'];

        yield [
            '![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)',
            '<em>Hi</em> ![Welcome to Hypervel](https://hypervel.org/assets/img/welcome/background.svg)',
        ];

        yield [
            'Visit https://hypervel.org/docs to browse the documentation',
            '<em>Hi</em> Visit https://hypervel.org/docs to browse the documentation',
        ];

        yield [
            'Visit <https://hypervel.org/docs> to browse the documentation',
            '<em>Hi</em> Visit &lt;https://hypervel.org/docs&gt; to browse the documentation',
        ];

        yield [
            'Visit <span>https://hypervel.org/docs</span> to browse the documentation',
            '<em>Hi</em> Visit &lt;span&gt;https://hypervel.org/docs&lt;/span&gt; to browse the documentation',
        ];

        // Encoding must block Markdown injection without deleting the user's backslashes.
        yield ['\[Hypervel](https://hypervel.org)', '<em>Hi</em> \[Hypervel](https://hypervel.org)'];

        yield ['!\[Hypervel](https://hypervel.org)', '<em>Hi</em> !\[Hypervel](https://hypervel.org)'];

        yield ['\\\server\share', '<em>Hi</em> \\\server\share'];
    }

    #[WithMigration]
    #[DataProvider('markdownEncodedLinkTextTemplateDataProvider')]
    public function testItCanAssertMarkdownEncodedStringUsingTemplateWithLinkText(string $given, string $expected): void
    {
        $user = UserFactory::new()->create([
            'name' => $given,
        ]);

        $mailable = new class($user) extends Mailable {
            public ?string $theme = 'taylor';

            /**
             * Create the mailable for the given user.
             */
            public function __construct(public User $user)
            {
            }

            /**
             * Build the mailable.
             */
            public function build(): static
            {
                return $this->markdown('link-with-template');
            }
        };

        $mailable->assertSeeInHtml($expected, false);
        $mailable->assertDontSeeInHtml('https://example.com"', false);
    }

    /**
     * Provide user text that must stay inside the template's link.
     */
    public static function markdownEncodedLinkTextTemplateDataProvider(): iterable
    {
        yield ['Taylor', '<a href="https://hypervel.org">Taylor</a>'];

        yield ['Taylor](https://example.com)', '<a href="https://hypervel.org">Taylor](https://example.com)</a>'];

        // Encoding must block Markdown injection without deleting the user's backslashes.
        yield ['Taylor\](https://example.com)', '<a href="https://hypervel.org">Taylor\](https://example.com)</a>'];
    }

    public function testSecuredRenderingPreservesLiteralContentAcrossMailComponents(): void
    {
        $value = '\\\server\share [x] <b>';
        $html = $this->app->make(Markdown::class)->render('message-with-punctuation', [
            'value' => $value,
            'punctuation' => '[x]',
            'host' => 'evil.test\\',
            'number' => 42,
        ])->toHtml();
        $document = new Crawler($html);

        $this->assertSame('literal \[ and \] and \\\ and &#91; and &lt;', $document->filter('code')->eq(0)->text());
        $this->assertSame($value, $document->filter('code')->eq(1)->text());
        $this->assertSame($value, $document->filter('.panel-item')->text());
        $this->assertSame($value, $document->filter('.subcopy')->text());
        $this->assertStringContainsString('content: "[x]"', $document->filter('style')->last()->text());
        $this->assertSame('https://evil.test%5C.good.test', $document->selectLink('Destination')->attr('href'));
        $this->assertContains('42', $document->filter('p')->each(fn (Crawler $paragraph): string => $paragraph->text()));
    }
}
