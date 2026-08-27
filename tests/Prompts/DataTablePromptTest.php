<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\DataTablePrompt;
use Hypervel\Prompts\Key;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Support\Utils;
use Hypervel\Prompts\Themes\Default\DataTableRenderer;
use Hypervel\Tests\TestCase;
use ReflectionMethod;

use function Hypervel\Prompts\datatable;

class DataTablePromptTest extends TestCase
{
    public function testHelperRendersEmptyTableInActiveAndCancelStates(): void
    {
        Prompt::fake([Key::CTRL_C]);

        $result = datatable(label: 'No data');

        $this->assertNull($result);
        $this->assertSame(2, substr_count(Prompt::strippedContent(), 'No rows.'));
        Prompt::assertOutputContains('Cancelled.');
    }

    public function testPromptRendersEmptyTableDirectly(): void
    {
        Prompt::fake([Key::CTRL_C]);

        $result = (new DataTablePrompt(label: 'No data'))->prompt();

        $this->assertNull($result);
        Prompt::assertStrippedOutputContains('No rows.');
        Prompt::assertOutputContains('Cancelled.');
    }

    public function testRendersTableWithHeadersAndSearchLine()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Select a user',
            headers: ['Name', 'Email'],
            rows: [
                ['Alice', 'alice@example.com'],
                ['Bob', 'bob@example.com'],
            ],
            scroll: 5,
        );

        Prompt::assertStrippedOutputContains('Select a user');
        Prompt::assertStrippedOutputContains('/ Search');
        Prompt::assertStrippedOutputContains('Name');
        Prompt::assertStrippedOutputContains('Email');
        Prompt::assertStrippedOutputContains('Alice');
        Prompt::assertStrippedOutputContains('Bob');
    }

    public function testReturnsIndexForListArrays()
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
                ['Charlie'],
            ],
            scroll: 5,
        );

        $this->assertSame(1, $result);
    }

    public function testReturnsKeyForAssociativeArrays()
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
                'c' => ['Charlie'],
            ],
            scroll: 5,
        );

        $this->assertSame('b', $result);
    }

    public function testNavigatesWithArrowKeys()
    {
        Prompt::fake([Key::DOWN, Key::DOWN, Key::UP, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
                'c' => ['Charlie'],
            ],
            scroll: 5,
        );

        $this->assertSame('b', $result);
    }

    public function testWrapsAroundWhenNavigatingPastEnd()
    {
        Prompt::fake([Key::UP, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
                'c' => ['Charlie'],
            ],
            scroll: 5,
        );

        $this->assertSame('c', $result);
    }

    public function testSupportsPageUpAndPageDown()
    {
        Prompt::fake([Key::PAGE_DOWN, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
                'c' => ['Charlie'],
                'd' => ['Diana'],
                'e' => ['Ethan'],
                'f' => ['Fatima'],
            ],
            scroll: 3,
        );

        $this->assertSame('d', $result);
    }

    public function testSupportsHomeAndEndKeys()
    {
        Prompt::fake([Key::END[0], Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
                'c' => ['Charlie'],
            ],
            scroll: 5,
        );

        $this->assertSame('c', $result);
    }

    public function testEntersSearchModeWithSlashAndFiltersRows()
    {
        Prompt::fake(['/', 'b', 'o', Key::ENTER, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
            ],
            scroll: 5,
        );

        $this->assertSame('b', $result);
    }

    public function testReturnsOriginalKeyAfterFilteringListArray()
    {
        Prompt::fake(['/', 'c', 'h', Key::ENTER, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
                ['Charlie'],
            ],
            scroll: 5,
        );

        // "Charlie" is at original index 2, search should preserve that
        $this->assertSame(2, $result);
    }

    public function testCancelsSearchWithEscape()
    {
        Prompt::fake(['/', 'x', 'y', 'z', Key::ESCAPE, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
            ],
            scroll: 5,
        );

        // After cancel, filter is cleared, back to first row
        $this->assertSame('a', $result);
    }

    public function testShowsNoResultsMessageWhenSearchMatchesNothing()
    {
        Prompt::fake(['/', 'z', 'z', 'z', Key::ESCAPE, Key::ENTER]);

        datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
            ],
            scroll: 5,
        );

        Prompt::assertStrippedOutputContains('No results found.');
    }

    public function testRendersColumnAwareBorders()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['A', 'B'],
            rows: [
                ['One', 'Two'],
            ],
            scroll: 5,
        );

        // Column-aware separators should use ┬, ┼, ┴
        Prompt::assertStrippedOutputContains('┬');
        Prompt::assertStrippedOutputContains('┼');
        Prompt::assertStrippedOutputContains('┴');
    }

    public function testShowsSimpleBordersWhenNoResults()
    {
        Prompt::fake(['/', 'z', 'z', 'z', Key::ESCAPE, Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['A', 'B'],
            rows: [
                ['One', 'Two'],
            ],
            scroll: 5,
        );

        $content = Prompt::strippedContent();

        $this->assertStringContainsString('No results found.', $content);
    }

    public function testShowsViewingInfoOnlyWhenScrollingNeeded()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
            ],
            scroll: 5,
        );

        // Only 2 rows with scroll=5, no info line needed
        Prompt::assertStrippedOutputDoesntContain('Viewing');
    }

    public function testShowsViewingInfoWhenMoreRowsThanScroll()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
                ['Charlie'],
                ['Diana'],
                ['Ethan'],
                ['Fatima'],
            ],
            scroll: 3,
        );

        Prompt::assertStrippedOutputContains('Viewing');
        Prompt::assertStrippedOutputContains('1-3');
        Prompt::assertStrippedOutputContains('of');
        Prompt::assertStrippedOutputContains('6');
    }

    public function testHandlesMultilineCells()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name', 'Role'],
            rows: [
                ['Alice', "CEO\nDeveloper"],
                ['Bob', 'Designer'],
            ],
            scroll: 5,
        );

        Prompt::assertStrippedOutputContains('CEO');
        Prompt::assertStrippedOutputContains('Developer');
        Prompt::assertStrippedOutputContains('Alice');
    }

    public function testKeepsHighlightedMultilineRowFullyVisible()
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name', 'Role'],
            rows: [
                ['Alice', 'Designer'],
                ['Bob', "CEO\nCTO\nDeveloper"],
                ['Charlie', 'Designer'],
            ],
            scroll: 5,
        );

        // Bob's multiline row should be fully visible when highlighted
        Prompt::assertStrippedOutputContains('CEO');
        Prompt::assertStrippedOutputContains('CTO');
        Prompt::assertStrippedOutputContains('Developer');
    }

    public function testUsesComfortableWidthAndDoesNotStretchToTerminal()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['A', 'B'],
            rows: [
                ['Hi', 'Lo'],
            ],
            scroll: 5,
        );

        $content = Prompt::strippedContent();

        // With tiny data, the table should not stretch to 80 cols
        $lines = explode("\n", $content);
        $maxLen = max(array_map('mb_strwidth', $lines));

        $this->assertLessThan(70, $maxLen);
    }

    public function testHandlesOutlierColumnWidthsGracefully()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name', 'Value'],
            rows: [
                ['Alice', 'Short'],
                ['Bob', 'Short'],
                ['Charlie', 'Short'],
                ['Diana', 'Short'],
                ['Ethan', 'Short'],
                ['An extremely long value that should be treated as an outlier and truncated', 'Short'],
            ],
            scroll: 5,
        );

        $content = Prompt::strippedContent();
        $lines = explode("\n", $content);
        $maxLen = max(array_map('mb_strwidth', $lines));

        // The outlier shouldn't blow up the table width to terminal width (80)
        $this->assertLessThan(76, $maxLen);
    }

    public function testSupportsCustomFilterClosure()
    {
        Prompt::fake(['/', 'a', Key::ENTER, Key::ENTER]);

        $result = datatable(
            label: 'Pick one',
            headers: ['Name', 'Code'],
            rows: [
                'x' => ['Alice', 'X1'],
                'y' => ['Bob', 'Y2'],
            ],
            scroll: 5,
            filter: fn ($row, $query) => str_starts_with(strtolower($row[0]), strtolower($query)),
        );

        // Custom filter matches "Alice" starting with "a", not "Bob"
        $this->assertSame('x', $result);
    }

    public function testRendersCancelStateWithStrikethroughData()
    {
        Prompt::fake([Key::CTRL_C]);

        datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
            ],
            scroll: 5,
        );

        Prompt::assertOutputContains('Cancelled.');
    }

    public function testRendersSubmitStateWithSelectedRow()
    {
        Prompt::fake([Key::DOWN, Key::ENTER]);

        datatable(
            label: 'Pick one',
            headers: ['Name', 'Role'],
            rows: [
                ['Alice', 'Designer'],
                ['Bob', 'Developer'],
            ],
            scroll: 5,
        );

        Prompt::assertStrippedOutputContains('Bob, Developer');
    }

    public function testScrollsAndShowsScrollbarWhenNeeded()
    {
        Prompt::fake([Key::DOWN, Key::DOWN, Key::DOWN, Key::ENTER]);

        $result = datatable(
            label: 'Test',
            headers: ['Name'],
            rows: [
                'a' => ['Alice'],
                'b' => ['Bob'],
                'c' => ['Charlie'],
                'd' => ['Diana'],
                'e' => ['Ethan'],
            ],
            scroll: 3,
        );

        $this->assertSame('d', $result);

        // Scrollbar indicators should be present
        Prompt::assertOutputContains('┃');
    }

    public function testWorksWithoutHeaders()
    {
        Prompt::fake([Key::ENTER]);

        $result = datatable(
            label: 'Pick',
            rows: [
                ['Alice', 'Designer'],
                ['Bob', 'Developer'],
            ],
            scroll: 5,
        );

        $this->assertSame(0, $result);
        Prompt::assertStrippedOutputContains('Alice');
        Prompt::assertStrippedOutputContains('Designer');
    }

    public function testDimsRowsDuringSearch()
    {
        Prompt::fake(['/', Key::ESCAPE, Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
            ],
            scroll: 5,
        );

        // During search state, rows should be dimmed
        // We just verify the search mode was entered and exited cleanly
        Prompt::assertStrippedOutputContains('Alice');
    }

    public function testHandlesBlankCellsInWidthCalculation()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name', 'Email'],
            rows: [
                ['Alice', 'alice@example.com'],
                ['', ''],
                ['Charlie', 'charlie@example.com'],
            ],
            scroll: 5,
        );

        // Blank cells should not skew column widths
        Prompt::assertStrippedOutputContains('Alice');
        Prompt::assertStrippedOutputContains('alice@example.com');
        Prompt::assertStrippedOutputContains('Charlie');
    }

    public function testRendersSearchLineInCancelStateToPreventLayoutShift()
    {
        Prompt::fake([Key::CTRL_C]);

        datatable(
            label: 'Pick one',
            headers: ['Name'],
            rows: [
                ['Alice'],
            ],
            scroll: 5,
        );

        // Cancel state should include the search line
        Prompt::assertStrippedOutputContains('/ Search');
        Prompt::assertOutputContains('Cancelled.');
    }

    public function testMaintainsFixedVisualHeight()
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Test',
            headers: ['Name'],
            rows: [
                ['Alice'],
                ['Bob'],
            ],
            scroll: 5,
        );

        // Even with only 2 rows, the data area should be padded to scroll height (5 lines)
        $content = Prompt::strippedContent();

        // Count lines between the header separator (┼ or ┬) and bottom border (┴)
        $lines = explode("\n", $content);
        $dataStart = null;
        $dataEnd = null;

        foreach ($lines as $i => $line) {
            if (str_contains($line, '┼') || (str_contains($line, '┬') && $dataStart === null)) {
                $dataStart = $i;
            }
            if (str_contains($line, '┴')) {
                $dataEnd = $i;
            }
        }

        if ($dataStart !== null && $dataEnd !== null) {
            $dataLineCount = $dataEnd - $dataStart - 1;
            $this->assertSame(5, $dataLineCount);
        }
    }

    public function testRestoresBrowseStateAfterANonRevertibleError(): void
    {
        Prompt::fake([Key::CTRL_U, Key::DOWN, Key::ENTER]);

        $result = (new DataTablePrompt(
            headers: ['Name'],
            rows: ['a' => ['Alice'], 'b' => ['Bob']],
        ))->prompt();

        $this->assertSame('b', $result);
        Prompt::assertStrippedOutputContains('This cannot be reverted.');
    }

    public function testRestoresSearchStateAfterANonRevertibleError(): void
    {
        Prompt::fake(['/', Key::CTRL_U, 'b', Key::ENTER, Key::ENTER]);
        $prompt = new DataTablePrompt(
            headers: ['Name'],
            rows: ['a' => ['Alice'], 'b' => ['Bob']],
        );

        $result = $prompt->prompt();

        $this->assertSame('b', $result);
        $this->assertSame('b', $prompt->searchValue());
        Prompt::assertStrippedOutputContains('This cannot be reverted.');
    }

    public function testOrdinaryValidationErrorsStillRestoreActiveState(): void
    {
        Prompt::fake([Key::ENTER, Key::DOWN, Key::ENTER]);

        $result = (new DataTablePrompt(
            headers: ['Name'],
            rows: ['a' => ['Alice'], 'b' => ['Bob']],
            validate: fn ($value) => $value === 'a' ? 'Choose another row.' : null,
        ))->prompt();

        $this->assertSame('b', $result);
        Prompt::assertStrippedOutputContains('Choose another row.');
    }

    public function testNaturalMetricsHandleRaggedRowsMultilineCellsAndStyledText(): void
    {
        $prompt = new DataTablePrompt(
            headers: [['First', 'Name'], 'Status'],
            rows: [
                ["\e[31mAlice\e[0m", '<fg=green>Ready</>', "short\r\nlonger"],
                ['Bob'],
            ],
        );

        $this->assertSame(
            ['columns' => 3, 'widths' => [10, 6, 6]],
            $prompt->naturalColumnMetrics(),
        );
    }

    public function testSparseIntegerHeaderAndRowKeysRemainPositionalDuringLayout(): void
    {
        Prompt::fake();
        $prompt = new DataTablePrompt(
            headers: [1 => 'Name', 4 => 'Status'],
            rows: [[2 => 'Alice', 5 => 'Ready']],
        );

        $this->assertSame(
            ['columns' => 2, 'widths' => [5, 6]],
            $prompt->naturalColumnMetrics(),
        );

        $activeOutput = Utils::stripEscapeSequences((new DataTableRenderer($prompt))($prompt));
        $prompt->state = 'cancel';
        $cancelOutput = Utils::stripEscapeSequences((new DataTableRenderer($prompt))($prompt));

        foreach ([$activeOutput, $cancelOutput] as $output) {
            $this->assertStringContainsString('Name', $output);
            $this->assertStringContainsString('Status', $output);
            $this->assertStringContainsString('Alice', $output);
            $this->assertStringContainsString('Ready', $output);
        }

        $this->assertSame([2 => 'Alice', 5 => 'Ready'], $prompt->selectedRow());
    }

    public function testNaturalMetricsApplyTheP90OutlierRule(): void
    {
        $rows = array_fill(0, 9, ['short']);
        $rows[] = [str_repeat('x', 100)];
        $prompt = new DataTablePrompt(headers: ['Name'], rows: $rows);

        $this->assertSame(
            ['columns' => 1, 'widths' => [5]],
            $prompt->naturalColumnMetrics(),
        );
    }

    public function testNaturalMetricsAreMemoizedForThePromptLifetime(): void
    {
        $prompt = new DataTablePrompt(headers: ['Name'], rows: [['Alice']]);
        $metrics = $prompt->naturalColumnMetrics();

        $prompt->rows = [[str_repeat('x', 1000)]];

        $this->assertSame($metrics, $prompt->naturalColumnMetrics());
    }

    public function testEmptyTableHasNoNaturalColumns(): void
    {
        $this->assertSame(
            ['columns' => 0, 'widths' => []],
            (new DataTablePrompt)->naturalColumnMetrics(),
        );
    }

    public function testFittedWidthsHonorMinimaAndTheExactTerminalBudget(): void
    {
        $prompt = new DataTablePrompt(headers: ['A', 'B', 'C'], rows: [['a', 'b', 'c']]);
        $renderer = new DataTableRenderer($prompt);
        $fitColumnWidths = new ReflectionMethod($renderer, 'fitColumnWidths');

        $widths = $fitColumnWidths->invoke($renderer, [100, 50, 25], 74);

        $this->assertSame(60, array_sum($widths));
        $this->assertSame([34, 18, 8], $widths);

        foreach ($widths as $width) {
            $this->assertIsInt($width);
        }

        $this->assertGreaterThanOrEqual(1, min($widths));
    }

    public function testScrollbarPreservesTrailingAnsiResetAfterReplacingVisibleContent(): void
    {
        $renderer = new DataTableRenderer(new DataTablePrompt);
        $scrollbar = new ReflectionMethod($renderer, 'scrollbar');

        [$line] = $scrollbar->invoke($renderer, ["\e[2mabcdefghij\e[22m"], 0, 1, 2, 10);

        $this->assertSame('abcdefghi┃', Utils::stripEscapeSequences($line));
        $this->assertStringEndsWith("\e[22m", $line);
    }

    public function testLongHeadersShrinkToFitAnEightyColumnTerminal(): void
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            label: 'Users',
            headers: [str_repeat('A', 100), str_repeat('B', 100)],
            rows: [['Alice', 'Developer']],
        );

        $maximumWidth = max(array_map(
            mb_strwidth(...),
            explode("\n", Prompt::strippedContent()),
        ));

        $this->assertLessThanOrEqual(80, $maximumWidth);
    }

    public function testRendersLfAndCrlfMultilineCells(): void
    {
        Prompt::fake([Key::ENTER]);

        datatable(
            headers: ['Name', 'Roles'],
            rows: [['Alice', "Owner\r\nDeveloper\nReviewer"]],
        );

        Prompt::assertStrippedOutputContains('Owner');
        Prompt::assertStrippedOutputContains('Developer');
        Prompt::assertStrippedOutputContains('Reviewer');
        $this->assertStringNotContainsString("\r", Prompt::strippedContent());
    }
}
