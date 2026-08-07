<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Hypervel\Tests\TestCase;

class AnsiWordwrapTest extends TestCase
{
    public function testWrapsPlainTextWithoutAnsiCodes(): void
    {
        $result = $this->getInstance()->wrap('Hello World', 5);

        $this->assertSame(['Hello', 'World'], $result);
    }

    public function testReturnsSingleLineWhenTextFitsWithinWidth(): void
    {
        $result = $this->getInstance()->wrap('Hello', 80);

        $this->assertSame(['Hello'], $result);
    }

    public function testPreservesAnsiCodesAcrossWordWrap(): void
    {
        $result = $this->getInstance()->wrap("\e[31mHello World\e[0m", 5);

        $this->assertCount(2, $result);
        // First line should have the red code and close
        $this->assertStringContainsString("\e[31m", $result[0]);
        $this->assertStringContainsString('Hello', $result[0]);
        // Second line should re-apply the red code
        $this->assertStringContainsString("\e[31m", $result[1]);
        $this->assertStringContainsString('World', $result[1]);
    }

    public function testHandlesTextWithColorChangeMidWrap(): void
    {
        $result = $this->getInstance()->wrap("\e[31mRed\e[0m \e[32mGreen text here\e[0m", 10);

        $this->assertStringContainsString('Red', $result[0]);
        $this->assertStringContainsString('Green', $result[0]);
        // "text here" should wrap to next line with green
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    public function testHandlesEmptyString(): void
    {
        $result = $this->getInstance()->wrap('', 80);

        $this->assertSame([''], $result);
    }

    public function testClosesOpenAnsiCodesAtEndOfWrappedLines(): void
    {
        $result = $this->getInstance()->wrap("\e[1mBold text that should wrap around\e[0m", 10);

        // Each line with active codes should end with a reset
        foreach ($result as $line) {
            if (str_contains($line, "\e[1m")) {
                $this->assertStringEndsWith("\e[0m", $line);
            }
        }
    }

    public function testWrapsTextWithMultiByteCharactersAndAnsiCodes(): void
    {
        $result = $this->getInstance()->wrap("\e[31mHêllo Wörld\e[0m", 6);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('Hêllo', $result[0]);
        $this->assertStringContainsString('Wörld', $result[1]);
    }

    public function testHandlesMultipleColorSegmentsWrappingAcrossLines(): void
    {
        $text = "\e[31mRed\e[0m \e[32mGreen\e[0m \e[34mBlue\e[0m";
        $result = $this->getInstance()->wrap($text, 5);

        // Each color word should be on its own line
        $this->assertCount(3, $result);
        $this->assertStringContainsString('Red', $result[0]);
        $this->assertStringContainsString('Green', $result[1]);
        $this->assertStringContainsString('Blue', $result[2]);
    }

    public function testPreservesUnstyledTextThatDoesNotNeedWrapping(): void
    {
        $result = $this->getInstance()->wrap('Short', 80);

        $this->assertSame(['Short'], $result);
    }

    public function testPreservesOsc8HyperlinkSequences(): void
    {
        $result = $this->getInstance()->wrap("Click \e]8;;https://example.com\e\\here\e]8;;\e\\", 80);

        $this->assertCount(1, $result);
        $this->assertStringContainsString("\e]8;;https://example.com\e\\", $result[0]);
        $this->assertStringContainsString('here', $result[0]);
        $this->assertStringContainsString("\e]8;;\e\\", $result[0]);
    }

    public function testClosesAndReopensOsc8HyperlinkWhenWrappingAcrossLines(): void
    {
        $result = $this->getInstance()->wrap("\e]8;;https://example.com\e\\Hello World\e]8;;\e\\", 5);

        $this->assertCount(2, $result);
        $this->assertStringContainsString("\e]8;;https://example.com\e\\", $result[0]);
        $this->assertStringContainsString('Hello', $result[0]);
        $this->assertStringEndsWith("\e]8;;\e\\", $result[0]);
        $this->assertStringContainsString("\e]8;;https://example.com\e\\", $result[1]);
        $this->assertStringContainsString('World', $result[1]);
        $this->assertStringEndsWith("\e]8;;\e\\", $result[1]);
    }

    public function testHandlesOsc8HyperlinkWithAnsiCodesInside(): void
    {
        $result = $this->getInstance()->wrap("\e]8;;https://example.com\e\\\e[4mLink Text\e[0m\e]8;;\e\\", 80);

        $this->assertCount(1, $result);
        $this->assertStringContainsString("\e]8;;https://example.com\e\\", $result[0]);
        $this->assertStringContainsString("\e[4m", $result[0]);
        $this->assertStringContainsString('Link Text', $result[0]);
    }

    public function testPreservesUnterminatedEscapeSequencesAsLiteralText(): void
    {
        $this->assertSame(["abc\e[31"], $this->getInstance()->wrap("abc\e[31", 80));
        $this->assertSame(
            ["abc\e]8;;https://hypervel.org"],
            $this->getInstance()->wrap("abc\e]8;;https://hypervel.org", 80),
        );
    }

    private function getInstance(): object
    {
        return new class {
            use InteractsWithStrings;

            protected int $minWidth = 0;

            public function wrap(string $text, int $width): array
            {
                return $this->ansiWordwrap($text, $width);
            }
        };
    }
}
