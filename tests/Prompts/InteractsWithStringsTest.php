<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Support\Utils;
use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InteractsWithStringsTest extends TestCase
{
    public function testReplacesTheLastVisibleGrapheme(): void
    {
        $this->assertSame('ab┃', $this->getInstance()->replace('abc', '┃'));
    }

    public function testPreservesTrailingAnsiFormattingAndDisplayWidth(): void
    {
        $line = "\e[2mabcdefghij\e[22m";
        $replacement = "\e[36m┃\e[39m";
        $result = $this->getInstance()->replace($line, $replacement);

        $this->assertSame("\e[2mabcdefghi{$replacement}\e[22m", $result);
        $this->assertSame(
            mb_strwidth(Utils::stripEscapeSequences($line)),
            mb_strwidth(Utils::stripEscapeSequences($result)),
        );
    }

    #[DataProvider('formattedLines')]
    public function testPreservesTrailingHyperlinkAndStyleClosers(string $line, string $expected): void
    {
        $this->assertSame($expected, $this->getInstance()->replace($line, '┃'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formattedLines(): array
    {
        return [
            'OSC 8 string terminator' => [
                "\e]8;;https://example.com\e\\link\e]8;;\e\\",
                "\e]8;;https://example.com\e\\lin┃\e]8;;\e\\",
            ],
            'OSC 8 bell terminator' => [
                "\e]8;;https://example.com\x07link\e]8;;\x07",
                "\e]8;;https://example.com\x07lin┃\e]8;;\x07",
            ],
            'named style' => ['<info>abc</info>', '<info>ab┃</info>'],
            'inline style' => ['<fg=red>abc</>', '<fg=red>ab┃</>'],
        ];
    }

    #[DataProvider('linesWithoutVisibleContent')]
    public function testLeavesLinesWithoutVisibleContentUnchanged(string $line): void
    {
        $this->assertSame($line, $this->getInstance()->replace($line, '┃'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function linesWithoutVisibleContent(): array
    {
        return [
            'empty' => [''],
            'ANSI reset' => ["\e[0m"],
            'OSC link open and close' => ["\e]8;;https://example.com\e\\\e]8;;\e\\"],
            'named style' => ['<info></info>'],
            'inline style' => ['<fg=red></>'],
        ];
    }

    public function testReplacesAWholeWideOrCombinedGrapheme(): void
    {
        $instance = $this->getInstance();

        $wide = $instance->replace('a界', '┃');
        $combined = $instance->replace("xe\u{0301}", '┃');
        $emoji = $instance->replace('x👨‍👩‍👧‍👦', '┃');

        $this->assertSame('a ┃', $wide);
        $this->assertSame(mb_strwidth('a界'), mb_strwidth($wide));
        $this->assertStringStartsWith('x', $combined);
        $this->assertStringNotContainsString("\u{0301}", $combined);
        $this->assertStringStartsWith('x', $emoji);
        $this->assertStringNotContainsString('👨', $emoji);
    }

    public function testLeavesInvalidUtf8Unchanged(): void
    {
        $line = "valid\xff";

        $this->assertSame($line, $this->getInstance()->replace($line, '┃'));
    }

    private function getInstance(): object
    {
        return new class {
            use InteractsWithStrings;

            protected int $minWidth = 0;

            public function replace(string $line, string $replacement): string
            {
                return $this->replaceLastVisibleGrapheme($line, $replacement);
            }
        };
    }
}
