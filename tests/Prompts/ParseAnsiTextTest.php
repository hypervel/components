<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ParseAnsiTextTest extends TestCase
{
    public function testParsesPlainTextIntoSingleSegment()
    {
        $segments = $this->getInstance()->parse('Hello, World!');

        $this->assertSame([
            ['text' => 'Hello, World!', 'codes' => ''],
        ], $segments);
    }

    public function testParsesTextWithSingleAnsiCode()
    {
        $segments = $this->getInstance()->parse("\e[31mHello\e[0m");

        $this->assertSame([
            ['text' => 'Hello', 'codes' => "\e[31m"],
        ], $segments);
    }

    public function testParsesTextWithMixedStyledAndUnstyledSegments()
    {
        $segments = $this->getInstance()->parse("Hello \e[1mBold\e[0m World");

        $this->assertSame([
            ['text' => 'Hello ', 'codes' => ''],
            ['text' => 'Bold', 'codes' => "\e[1m"],
            ['text' => ' World', 'codes' => ''],
        ], $segments);
    }

    public function testParsesTextWithMultipleConsecutiveAnsiCodes()
    {
        $segments = $this->getInstance()->parse("\e[31mRed\e[0m \e[32mGreen\e[0m \e[34mBlue\e[0m");

        $this->assertSame([
            ['text' => 'Red', 'codes' => "\e[31m"],
            ['text' => ' ', 'codes' => ''],
            ['text' => 'Green', 'codes' => "\e[32m"],
            ['text' => ' ', 'codes' => ''],
            ['text' => 'Blue', 'codes' => "\e[34m"],
        ], $segments);
    }

    public function testParsesEmptyString()
    {
        $segments = $this->getInstance()->parse('');

        $this->assertSame([], $segments);
    }

    public function testParsesTextWith24BitColorCodes()
    {
        $segments = $this->getInstance()->parse("\e[38;2;255;100;50mColored\e[0m");

        $this->assertSame([
            ['text' => 'Colored', 'codes' => "\e[38;2;255;100;50m"],
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
