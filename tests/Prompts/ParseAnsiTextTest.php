<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Hypervel\Tests\TestCase;

class ParseAnsiTextTest extends TestCase
{
    public function testParsesPlainTextIntoSingleSegment(): void
    {
        $segments = $this->getInstance()->parse('Hello, World!');

        $this->assertSame([
            ['text' => 'Hello, World!', 'codes' => '', 'link' => ''],
        ], $segments);
    }

    public function testParsesTextWithSingleAnsiCode(): void
    {
        $segments = $this->getInstance()->parse("\e[31mHello\e[0m");

        $this->assertSame([
            ['text' => 'Hello', 'codes' => "\e[31m", 'link' => ''],
        ], $segments);
    }

    public function testParsesTextWithMixedStyledAndUnstyledSegments(): void
    {
        $segments = $this->getInstance()->parse("Hello \e[1mBold\e[0m World");

        $this->assertSame([
            ['text' => 'Hello ', 'codes' => '', 'link' => ''],
            ['text' => 'Bold', 'codes' => "\e[1m", 'link' => ''],
            ['text' => ' World', 'codes' => '', 'link' => ''],
        ], $segments);
    }

    public function testParsesTextWithMultipleConsecutiveAnsiCodes(): void
    {
        $segments = $this->getInstance()->parse("\e[31mRed\e[0m \e[32mGreen\e[0m \e[34mBlue\e[0m");

        $this->assertSame([
            ['text' => 'Red', 'codes' => "\e[31m", 'link' => ''],
            ['text' => ' ', 'codes' => '', 'link' => ''],
            ['text' => 'Green', 'codes' => "\e[32m", 'link' => ''],
            ['text' => ' ', 'codes' => '', 'link' => ''],
            ['text' => 'Blue', 'codes' => "\e[34m", 'link' => ''],
        ], $segments);
    }

    public function testParsesEmptyString(): void
    {
        $segments = $this->getInstance()->parse('');

        $this->assertSame([], $segments);
    }

    public function testParsesTextWith24BitColorCodes(): void
    {
        $segments = $this->getInstance()->parse("\e[38;2;255;100;50mColored\e[0m");

        $this->assertSame([
            ['text' => 'Colored', 'codes' => "\e[38;2;255;100;50m", 'link' => ''],
        ], $segments);
    }

    public function testNonSgrCsiDoesNotDiscardLaterTextOrStyles(): void
    {
        $segments = $this->getInstance()->parse("\e[2Kalpha \e[32mbeta\e[39m gamma");

        $this->assertSame([
            ['text' => 'alpha ', 'codes' => '', 'link' => ''],
            ['text' => 'beta', 'codes' => "\e[32m", 'link' => ''],
            ['text' => ' gamma', 'codes' => "\e[39m", 'link' => ''],
        ], $segments);
    }

    public function testBelTerminatedTitleDoesNotDiscardLaterTextOrBecomeALink(): void
    {
        $segments = $this->getInstance()->parse("\e]0;Hypervel\x07alpha \e[32mbeta\e[0m");

        $this->assertSame([
            ['text' => 'alpha ', 'codes' => '', 'link' => ''],
            ['text' => 'beta', 'codes' => "\e[32m", 'link' => ''],
        ], $segments);
    }

    public function testParsesBelTerminatedHyperlinks(): void
    {
        $segments = $this->getInstance()->parse("Click \e]8;;https://hypervel.org\x07here\e]8;;\x07.");

        $this->assertSame([
            ['text' => 'Click ', 'codes' => '', 'link' => ''],
            ['text' => 'here', 'codes' => '', 'link' => "\e]8;;https://hypervel.org\x07"],
            ['text' => '.', 'codes' => '', 'link' => ''],
        ], $segments);
    }

    public function testParsesParameterizedHyperlinks(): void
    {
        $segments = $this->getInstance()->parse("Click \e]8;id=hypervel;https://hypervel.org\e\\here\e]8;;\e\\.");

        $this->assertSame([
            ['text' => 'Click ', 'codes' => '', 'link' => ''],
            ['text' => 'here', 'codes' => '', 'link' => "\e]8;id=hypervel;https://hypervel.org\e\\"],
            ['text' => '.', 'codes' => '', 'link' => ''],
        ], $segments);
    }

    public function testParsesParameterizedHyperlinkClosers(): void
    {
        $stSegments = $this->getInstance()->parse("Click \e]8;id=hypervel;https://hypervel.org\e\\here\e]8;id=hypervel;\e\\.");
        $belSegments = $this->getInstance()->parse("Click \e]8;id=hypervel;https://hypervel.org\x07here\e]8;id=hypervel;\x07.");

        $this->assertSame([
            ['text' => 'Click ', 'codes' => '', 'link' => ''],
            ['text' => 'here', 'codes' => '', 'link' => "\e]8;id=hypervel;https://hypervel.org\e\\"],
            ['text' => '.', 'codes' => '', 'link' => ''],
        ], $stSegments);
        $this->assertSame([
            ['text' => 'Click ', 'codes' => '', 'link' => ''],
            ['text' => 'here', 'codes' => '', 'link' => "\e]8;id=hypervel;https://hypervel.org\x07"],
            ['text' => '.', 'codes' => '', 'link' => ''],
        ], $belSegments);
    }

    public function testPreservesSemicolonsInHyperlinkUris(): void
    {
        $segments = $this->getInstance()->parse("Click \e]8;;https://hypervel.org/?first=1;second=2;\e\\here\e]8;;\e\\.");

        $this->assertSame([
            ['text' => 'Click ', 'codes' => '', 'link' => ''],
            ['text' => 'here', 'codes' => '', 'link' => "\e]8;;https://hypervel.org/?first=1;second=2;\e\\"],
            ['text' => '.', 'codes' => '', 'link' => ''],
        ], $segments);
    }

    public function testCsiIntermediateBytesDoNotDiscardLaterStyles(): void
    {
        $segments = $this->getInstance()->parse("\e[5 qalpha \e[32mbeta\e[39m");

        $this->assertSame([
            ['text' => 'alpha ', 'codes' => '', 'link' => ''],
            ['text' => 'beta', 'codes' => "\e[32m", 'link' => ''],
        ], $segments);
    }

    public function testPreservesUnterminatedCsiAsText(): void
    {
        $segments = $this->getInstance()->parse("abc\e[31");

        $this->assertSame([
            ['text' => 'abc', 'codes' => '', 'link' => ''],
            ['text' => "\e[31", 'codes' => '', 'link' => ''],
        ], $segments);
    }

    public function testPreservesUnterminatedOscAsText(): void
    {
        $segments = $this->getInstance()->parse("abc\e]8;;https://hypervel.org");

        $this->assertSame([
            ['text' => 'abc', 'codes' => '', 'link' => ''],
            ['text' => "\e]8;;https://hypervel.org", 'codes' => '', 'link' => ''],
        ], $segments);
    }

    private function getInstance(): object
    {
        return new class {
            use InteractsWithStrings;

            protected int $minWidth = 0;

            public function parse(string $text): array
            {
                return $this->parseAnsiText($text);
            }
        };
    }
}
