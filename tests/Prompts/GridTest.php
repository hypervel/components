<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Grid;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GridTest extends TestCase
{
    #[DataProvider('multipleItemsProvider')]
    public function testRendersGridWithMultipleItems($items)
    {
        Prompt::fake();

        (new Grid($items, maxWidth: 80))->display();

        Prompt::assertStrippedOutputContains('pest');
        Prompt::assertStrippedOutputContains('phpstan');
        Prompt::assertStrippedOutputContains('pint');
        Prompt::assertStrippedOutputContains('rector');
    }

    public static function multipleItemsProvider(): array
    {
        return [
            'arrays' => [['pest', 'phpstan', 'pint', 'rector']],
            'collections' => [collect(['pest', 'phpstan', 'pint', 'rector'])],
        ];
    }

    #[DataProvider('singleItemProvider')]
    public function testRendersGridWithSingleItem($items)
    {
        Prompt::fake();

        (new Grid($items, maxWidth: 80))->display();

        Prompt::assertStrippedOutputContains('laravel');
    }

    public static function singleItemProvider(): array
    {
        return [
            'arrays' => [['laravel']],
            'collections' => [collect(['laravel'])],
        ];
    }

    #[DataProvider('emptyItemsProvider')]
    public function testRendersEmptyGridWithoutOutput($items)
    {
        Prompt::fake();

        (new Grid($items, maxWidth: 80))->display();

        $this->assertSame('', Prompt::content());
    }

    public static function emptyItemsProvider(): array
    {
        return [
            'arrays' => [[]],
            'collections' => [collect()],
        ];
    }

    #[DataProvider('unicodeItemsProvider')]
    public function testRendersGridWithUnicodeCharacters($items)
    {
        Prompt::fake();

        (new Grid($items, maxWidth: 80))->display();

        Prompt::assertStrippedOutputContains('測試');
        Prompt::assertStrippedOutputContains('café');
        Prompt::assertStrippedOutputContains('🚀');
    }

    public static function unicodeItemsProvider(): array
    {
        return [
            'arrays' => [['測試', 'café', '🚀']],
            'collections' => [collect(['測試', 'café', '🚀'])],
        ];
    }

    public function testRendersBoxDrawingCharactersForBorders()
    {
        Prompt::fake();

        (new Grid(['item1', 'item2'], maxWidth: 80))->display();

        $output = Prompt::content();

        $this->assertStringContainsString('┌', $output);
        $this->assertStringContainsString('┐', $output);
        $this->assertStringContainsString('└', $output);
        $this->assertStringContainsString('┘', $output);
        $this->assertStringContainsString('│', $output);
        $this->assertStringContainsString('─', $output);
    }

    public function testRendersTableSeparatorsBetweenMultipleRows()
    {
        Prompt::fake();

        (new Grid(['item1', 'item2', 'item3', 'item4', 'item5', 'item6', 'item7', 'item8', 'item9', 'item10'], maxWidth: 50))->display();

        $output = Prompt::content();

        $this->assertStringContainsString('├', $output);
        $this->assertStringContainsString('┤', $output);
    }

    public function testRespectsCustomMaxWidthParameter()
    {
        Prompt::fake();

        (new Grid(['item1', 'item2', 'item3'], maxWidth: 40))->display();

        $output = Prompt::content();

        $this->assertStringContainsString('item1', $output);
        $this->assertStringContainsString('item2', $output);
        $this->assertStringContainsString('item3', $output);
    }

    public function testUsesDefaultTerminalWidthWhenMaxWidthNotProvided()
    {
        Prompt::fake();

        (new Grid(['item1', 'item2']))->display();

        $output = Prompt::content();

        $this->assertStringContainsString('item1', $output);
        $this->assertStringContainsString('item2', $output);
    }

    public function testHandlesGridItemsWithVaryingCharacterLengths()
    {
        Prompt::fake();

        (new Grid(['a', 'medium-length-item', 'xyz'], maxWidth: 80))->display();

        Prompt::assertStrippedOutputContains('a');
        Prompt::assertStrippedOutputContains('medium-length-item');
        Prompt::assertStrippedOutputContains('xyz');
    }

    public function testArrangesManyItemsInBalancedColumnsAcrossMultipleRows()
    {
        Prompt::fake();

        $items = ['item1', 'item2', 'item3', 'item4', 'item5', 'item6', 'item7', 'item8', 'item9'];

        (new Grid($items, maxWidth: 80))->display();

        foreach ($items as $item) {
            Prompt::assertStrippedOutputContains($item);
        }
    }

    public function testRendersGridItemsContainingSpecialCharacters()
    {
        Prompt::fake();

        (new Grid(['@laravel', '#boost', '$100', '%progress'], maxWidth: 80))->display();

        Prompt::assertStrippedOutputContains('@laravel');
        Prompt::assertStrippedOutputContains('#boost');
        Prompt::assertStrippedOutputContains('$100');
        Prompt::assertStrippedOutputContains('%progress');
    }

    public function testPadsIncompleteRowsWithEmptyCellsToMaintainGridStructure()
    {
        Prompt::fake();

        (new Grid(['item1', 'item2', 'item3', 'item4', 'item5'], maxWidth: 80))->display();

        $output = Prompt::content();

        $this->assertStringContainsString('item1', $output);
        $this->assertStringContainsString('item2', $output);
        $this->assertStringContainsString('item3', $output);
        $this->assertStringContainsString('item4', $output);
        $this->assertStringContainsString('item5', $output);
        $this->assertStringContainsString('│', $output);
    }

    public function testReturnsTrueWhenPromptMethodCalled()
    {
        Prompt::fake();

        $grid = new Grid(['item1', 'item2'], maxWidth: 80);

        $this->assertTrue($grid->prompt());
    }

    public function testReturnsTrueWhenValueMethodCalled()
    {
        Prompt::fake();

        $grid = new Grid(['item1', 'item2'], maxWidth: 80);

        $this->assertTrue($grid->value());
    }

    public function testSetsPromptStateToSubmitAfterRendering()
    {
        Prompt::fake();

        $grid = new Grid(['item1'], maxWidth: 80);
        $grid->prompt();

        $this->assertSame('submit', $grid->state);
    }

    public function testDisplaysGridItemsWhenDisplayMethodCalled()
    {
        Prompt::fake();

        $grid = new Grid(['item1', 'item2'], maxWidth: 80);
        $grid->display();

        Prompt::assertStrippedOutputContains('item1');
        Prompt::assertStrippedOutputContains('item2');
    }

    public function testDoesNotOutputGridItemsUntilDisplayMethodCalled()
    {
        Prompt::fake();

        new Grid(['item1', 'item2'], maxWidth: 80);

        Prompt::assertStrippedOutputDoesntContain('item1');
    }

    public function testRendersCompleteGridWithMultipleRowsAndBalancedColumns()
    {
        Prompt::fake();

        (new Grid([
            'building-livewire-components',
            'building-mcp-servers',
            'testing-with-pest',
            'using-fluxui',
            'using-folio-routing',
            'using-tailwindcss',
        ], maxWidth: 120))->display();

        Prompt::assertStrippedOutputContains('┌──────────────────────────────┬──────────────────────┬───────────────────┐');
        Prompt::assertStrippedOutputContains('│ building-livewire-components │ building-mcp-servers │ testing-with-pest │');
        Prompt::assertStrippedOutputContains('├──────────────────────────────┼──────────────────────┼───────────────────┤');
        Prompt::assertStrippedOutputContains('│ using-fluxui                 │ using-folio-routing  │ using-tailwindcss │');
        Prompt::assertStrippedOutputContains('└──────────────────────────────┴──────────────────────┴───────────────────┘');
    }
}
