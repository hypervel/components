<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AnsiWordwrapTest extends TestCase
{
    public function testWrapsPlainTextWithoutAnsiCodes()
    {
        $result = $this->getInstance()->wrap('Hello World', 5);

        $this->assertSame(['Hello', 'World'], $result);
    }

    public function testReturnsSingleLineWhenTextFitsWithinWidth()
    {
        $result = $this->getInstance()->wrap('Hello', 80);

        $this->assertSame(['Hello'], $result);
    }

    public function testPreservesAnsiCodesAcrossWordWrap()
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

    public function testHandlesTextWithColorChangeMidWrap()
    {
        $result = $this->getInstance()->wrap("\e[31mRed\e[0m \e[32mGreen text here\e[0m", 10);

        $this->assertStringContainsString('Red', $result[0]);
        $this->assertStringContainsString('Green', $result[0]);
        // "text here" should wrap to next line with green
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    public function testHandlesEmptyString()
    {
        $result = $this->getInstance()->wrap('', 80);

        $this->assertSame([''], $result);
    }

    public function testClosesOpenAnsiCodesAtEndOfWrappedLines()
    {
        $result = $this->getInstance()->wrap("\e[1mBold text that should wrap around\e[0m", 10);

        // Each line with active codes should end with a reset
        foreach ($result as $line) {
            if (str_contains($line, "\e[1m")) {
                $this->assertStringEndsWith("\e[0m", $line);
            }
        }
    }

    public function testWrapsTextWithMultiByteCharactersAndAnsiCodes()
    {
        $result = $this->getInstance()->wrap("\e[31mHêllo Wörld\e[0m", 6);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('Hêllo', $result[0]);
        $this->assertStringContainsString('Wörld', $result[1]);
    }

    public function testHandlesMultipleColorSegmentsWrappingAcrossLines()
    {
        $text = "\e[31mRed\e[0m \e[32mGreen\e[0m \e[34mBlue\e[0m";
        $result = $this->getInstance()->wrap($text, 5);

        // Each color word should be on its own line
        $this->assertCount(3, $result);
        $this->assertStringContainsString('Red', $result[0]);
        $this->assertStringContainsString('Green', $result[1]);
        $this->assertStringContainsString('Blue', $result[2]);
    }

    public function testPreservesUnstyledTextThatDoesNotNeedWrapping()
    {
        $result = $this->getInstance()->wrap('Short', 80);

        $this->assertSame(['Short'], $result);
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
