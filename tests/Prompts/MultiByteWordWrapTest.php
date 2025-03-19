<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Concerns\Truncation;
use PHPUnit\Framework\TestCase;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class MultiByteWordWrapTest extends TestCase
{
    public function testWillMatchWordwrap()
    {
        $instance = $this->getInstance();

        $str = "This is a story all about how my life got flipped turned upside down and I'd like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $result = wordwrap($str);

        $mbResult = $instance->wordwrap($str);

        $this->assertSame($result, $mbResult);
    }

    public function testWillMatchWordwrapOnShorterStrings()
    {
        $instance = $this->getInstance();

        $str = "This is a story all\nabout how my life got\nflipped turned upside down and I'd like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $result = wordwrap($str);

        $mbResult = $instance->wordwrap($str);

        $this->assertSame($result, $mbResult);
    }

    public function testWillMatchWordwrapOnBlankLinesStrings()
    {
        $instance = $this->getInstance();

        $str = "This is a story all about how my life got flipped turned upside down and I'd\n\nlike to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $result = wordwrap($str);

        $mbResult = $instance->wordwrap($str);

        $this->assertSame($result, $mbResult);
    }

    public function testWillMatchWordwrapWithCustomLength()
    {
        $instance = $this->getInstance();

        $str = "This is a story all about how my life got flippppppppppppppppppppppppped turned upside down and I'd like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $result = wordwrap($str, 25);

        $mbResult = $instance->wordwrap($str, 25);

        $this->assertSame($result, $mbResult);
    }

    public function testWillMatchWordwrapWithRandomMultipleSpaces()
    {
        $instance = $this->getInstance();

        $str = "     This is a story all about how my life got flipped turned upside down and      I'd      like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $result = wordwrap($str, 25, "\n", true);

        $mbResult = $instance->wordwrap($str, 25, "\n", true);

        $this->assertSame($result, $mbResult);
    }

    public function testWillMatchWordwrapWithCutLongWordsDisabled()
    {
        $instance = $this->getInstance();

        $str = "This is a story all about how my life got flippppppppppppppppppppppppped turned upside down and I'd like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $result = wordwrap($str, 25, "\n", false);

        $mbResult = $instance->wordwrap($str, 25, "\n", false);

        $this->assertSame($result, $mbResult);
    }

    public function testWillWrapStringsWithMultiByteCharacters()
    {
        $instance = $this->getInstance();

        $str = "This is a story all about how my life got flippêd turnêd upsidê down and I'd likê to takê a minutê just sit right thêrê I'll têll you how I bêcamê thê princê of a town callêd Bêl-Air";

        $mbResult = $instance->wordwrap($str, 18, "\n", false);

        $expectedResult = <<<'RESULT'
        This is a story
        all about how my
        life got flippêd
        turnêd upsidê down
        and I'd likê to
        takê a minutê just
        sit right thêrê
        I'll têll you how
        I bêcamê thê
        princê of a town
        callêd Bêl-Air
        RESULT;

        $this->assertSame($mbResult, $expectedResult);
    }

    public function testWillWrapStringsWithEmojis()
    {
        $instance = $this->getInstance();

        $str = "This is a 📖 all about how my life got 🌀 turned upside ⬇️ and I'd like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $mbResult = $instance->wordwrap($str, 13, "\n", false);

        $expectedResult = <<<'RESULT'
        This is a 📖
        all about how
        my life got
        🌀 turned
        upside ⬇️ and
        I'd like to
        take a minute
        just sit
        right there
        I'll tell you
        how I became
        the prince of
        a town called
        Bel-Air
        RESULT;

        $this->assertSame($mbResult, $expectedResult);
    }

    public function testWillWrapStringsWithEmojisAndMultiByteCharacters()
    {
        $instance = $this->getInstance();

        $str = "This is a 📖 all about how my lifê got 🌀 turnêd upsidê ⬇️ and I'd likê to takê a minutê just sit right thêrê I'll têll you how I bêcamê thê princê of a town callêd Bêl-Air";

        $mbResult = $instance->wordwrap($str, 11, "\n", false);

        $expectedResult = <<<'RESULT'
        This is a
        📖 all
        about how
        my lifê got
        🌀 turnêd
        upsidê ⬇️
        and I'd
        likê to
        takê a
        minutê just
        sit right
        thêrê I'll
        têll you
        how I
        bêcamê thê
        princê of a
        town callêd
        Bêl-Air
        RESULT;

        $this->assertSame($mbResult, $expectedResult);
    }

    public function testWillWrapStringsWithCombinedEmojis()
    {
        $instance = $this->getInstance();

        $str = "This is a 📖 all about how my life got 🌀 turned upside ⬇️ and I'd like to take a minute just sit right there I'll tell you how I became the prince of a 👨‍👩‍👧‍👦 called Bel-Air";

        $mbResult = $instance->wordwrap($str, 13, "\n", false);

        $expectedResult = <<<'RESULT'
        This is a 📖
        all about how
        my life got
        🌀 turned
        upside ⬇️ and
        I'd like to
        take a minute
        just sit
        right there
        I'll tell you
        how I became
        the prince of
        a 👨‍👩‍👧‍👦 called
        Bel-Air
        RESULT;

        $this->assertSame($mbResult, $expectedResult);
    }

    public function testWillHandleLongStringsWithCutLongWordsEnabled()
    {
        $instance = $this->getInstance();

        $str = "This is a story all about how my life got flipped turned upside down and I'd like to take a minute just sit right there I'll tell you how I became the prince of a town called Bel-Air";

        $mbResult = $instance->wordwrap($str, 13, "\n", false);

        $expectedResult = <<<'RESULT'
        This is a
        story all
        about how my
        life got
        flipped
        turned upside
        down and I'd
        like to take
        a minute just
        sit right
        there I'll
        tell you how
        I became the
        prince of a
        town called
        Bel-Air
        RESULT;

        $this->assertSame($mbResult, $expectedResult);
    }

    protected function getInstance()
    {
        return new class {
            use Truncation;

            public function wordwrap(...$args)
            {
                return $this->mbWordwrap(...$args);
            }
        };
    }
}
