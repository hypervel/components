<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hyperf\Collection\Collection;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\table;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class TableTest extends TestCase
{
    /**
     * @dataProvider tableWithHeadersProvider
     */
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

    /**
     * @dataProvider tableWithoutHeadersProvider
     */
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
