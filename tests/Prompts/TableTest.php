<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

use function Hypervel\Prompts\table;

class TableTest extends TestCase
{
    #[DataProvider('decorationModes')]
    public function testFormatsCellsOnceAndPreservesEscapedMarkup(bool $decorated): void
    {
        Prompt::fake();
        $output = new BufferedConsoleOutput(decorated: $decorated);
        $output->getFormatter()->setStyle('warning', new OutputFormatterStyle('yellow'));
        Prompt::setOutput($output);

        table(['Value'], [[OutputFormatter::escape('<fg=black;bg=black>Hidden')], ['<warning>Careful</warning>']]);

        $content = Prompt::content();
        $this->assertStringContainsString('<fg=black;bg=black>Hidden', $content);
        $this->assertStringNotContainsString("\e[30;40m", $content);
        $this->assertStringContainsString('Careful', Utils::stripTerminalControls($content));
        $this->assertStringNotContainsString('<warning>', $content);
        $this->assertSame($decorated, $output->isDecorated());

        if ($decorated) {
            $this->assertStringContainsString("\e[33m", $content);
        } else {
            $this->assertStringNotContainsString("\e", $content);
        }
    }

    /**
     * Provide interactive and redirected output modes.
     */
    public static function decorationModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('tableWithHeadersProvider')]
    public function testRendersTableWithHeaders(array|Collection $headers, array|Collection $rows): void
    {
        Prompt::fake();

        table($headers, $rows);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌────────────────────┬──────────────────┐
         │ Name               │ Twitter          │
         ├────────────────────┼──────────────────┤
         │ Taylor Otwell      │ @taylorotwell    │
         │ Dries Vints        │ @driesvints      │
         │ James Brooks       │ @jbrooksuk       │
         │ Nuno Maduro        │ @enunomaduro     │
         │ Mior Muhammad Zaki │ @crynobone       │
         │ Jess Archer        │ @jessarchercodes │
         │ Guus Leeuw         │ @phpguus         │
         │ Tim MacDonald      │ @timacdonald87   │
         │ Joe Dixon          │ @_joedixon       │
         └────────────────────┴──────────────────┘
        OUTPUT);
    }

    public static function tableWithHeadersProvider(): array
    {
        return [
            'arrays' => [
                ['Name', 'Twitter'],
                [
                    ['Taylor Otwell', '@taylorotwell'],
                    ['Dries Vints', '@driesvints'],
                    ['James Brooks', '@jbrooksuk'],
                    ['Nuno Maduro', '@enunomaduro'],
                    ['Mior Muhammad Zaki', '@crynobone'],
                    ['Jess Archer', '@jessarchercodes'],
                    ['Guus Leeuw', '@phpguus'],
                    ['Tim MacDonald', '@timacdonald87'],
                    ['Joe Dixon', '@_joedixon'],
                ],
            ],
            'collections' => [
                Collection::make(['Name', 'Twitter']),
                Collection::make([
                    ['Taylor Otwell', '@taylorotwell'],
                    ['Dries Vints', '@driesvints'],
                    ['James Brooks', '@jbrooksuk'],
                    ['Nuno Maduro', '@enunomaduro'],
                    ['Mior Muhammad Zaki', '@crynobone'],
                    ['Jess Archer', '@jessarchercodes'],
                    ['Guus Leeuw', '@phpguus'],
                    ['Tim MacDonald', '@timacdonald87'],
                    ['Joe Dixon', '@_joedixon'],
                ]),
            ],
        ];
    }

    #[DataProvider('tableWithoutHeadersProvider')]
    public function testRendersTableWithoutHeaders(array|Collection $rows): void
    {
        Prompt::fake();

        table($rows);

        Prompt::assertStrippedOutputContains(<<<'OUTPUT'
         ┌────────────────────┬──────────────────┐
         │ Taylor Otwell      │ @taylorotwell    │
         │ Dries Vints        │ @driesvints      │
         │ James Brooks       │ @jbrooksuk       │
         │ Nuno Maduro        │ @enunomaduro     │
         │ Mior Muhammad Zaki │ @crynobone       │
         │ Jess Archer        │ @jessarchercodes │
         │ Guus Leeuw         │ @phpguus         │
         │ Tim MacDonald      │ @timacdonald87   │
         │ Joe Dixon          │ @_joedixon       │
         └────────────────────┴──────────────────┘
        OUTPUT);
    }

    public static function tableWithoutHeadersProvider(): array
    {
        return [
            'arrays' => [[
                ['Taylor Otwell', '@taylorotwell'],
                ['Dries Vints', '@driesvints'],
                ['James Brooks', '@jbrooksuk'],
                ['Nuno Maduro', '@enunomaduro'],
                ['Mior Muhammad Zaki', '@crynobone'],
                ['Jess Archer', '@jessarchercodes'],
                ['Guus Leeuw', '@phpguus'],
                ['Tim MacDonald', '@timacdonald87'],
                ['Joe Dixon', '@_joedixon'],
            ]],
            'collections' => [
                Collection::make([
                    ['Taylor Otwell', '@taylorotwell'],
                    ['Dries Vints', '@driesvints'],
                    ['James Brooks', '@jbrooksuk'],
                    ['Nuno Maduro', '@enunomaduro'],
                    ['Mior Muhammad Zaki', '@crynobone'],
                    ['Jess Archer', '@jessarchercodes'],
                    ['Guus Leeuw', '@phpguus'],
                    ['Tim MacDonald', '@timacdonald87'],
                    ['Joe Dixon', '@_joedixon'],
                ]),
            ],
        ];
    }
}
