<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Mail\Mailable;
use Hypervel\Mail\Markdown;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class MailableWithSecuredEncodingTest extends MailableTestCase
{
    use LazilyRefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        Markdown::withSecuredEncoding();
    }

    #[WithMigration]
    #[DataProvider('markdownEncodedTemplateDataProvider')]
    public function testItCanAssertMarkdownEncodedStringUsingTemplate($given, $expected)
    {
        $user = UserFactory::new()->create([
            'name' => $given,
        ]);

        $mailable = new class($user) extends Mailable {
            public ?string $theme = 'taylor';

            public function __construct(public User $user)
            {
            }

            public function build()
            {
                return $this->markdown('message-with-template');
            }
        };

        $mailable->assertSeeInHtml($expected, false);
    }

    #[WithMigration]
    #[DataProvider('markdownEncodedTemplateDataProvider')]
    public function testItCanAssertMarkdownEncodedStringUsingTemplateWithTable($given, $expected)
    {
        $user = UserFactory::new()->create([
            'name' => $given,
        ]);

        $mailable = new class($user) extends Mailable {
            public ?string $theme = 'taylor';

            public function __construct(public User $user)
            {
            }

            public function build()
            {
                return $this->markdown('table-with-template');
            }
        };

        $mailable->assertSeeInHtml($expected, false);
        $mailable->assertSeeInHtml('<p>This is a subcopy</p>', false);
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

    public static function markdownEncodedTemplateDataProvider()
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
    }
}
