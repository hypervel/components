<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Stream;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Prompts\stream;

/**
 * @internal
 * @coversNothing
 */
class StreamTest extends TestCase
{
    public function testRendersAppendedText()
    {
        Prompt::fake();

        $stream = stream();
        $stream->append('Hello, ');
        $stream->append('World!');
        $stream->close();

        Prompt::assertOutputContains('Hello, ');
        Prompt::assertOutputContains('World!');
    }

    public function testReturnsFullMessageAsValue()
    {
        Prompt::fake();

        $stream = stream();
        $stream->append('Hello, ');
        $stream->append('World!');
        $stream->close();

        $this->assertSame('Hello, World!', $stream->value());
    }

    public function testAccumulatesMessageProperty()
    {
        Prompt::fake();

        $stream = stream();
        $stream->append('foo');
        $stream->append('bar');
        $stream->append('baz');

        // After enough appends exceed fading colors count, earlier messages move to $message
        $stream->close();

        $this->assertSame('foobarbaz', $stream->value());
    }

    public function testThrowsWhenPromptCalled()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream cannot be prompted');

        Prompt::fake();

        $stream = new Stream;
        $stream->prompt();
    }

    public function testReturnsLinesFromStream()
    {
        Prompt::fake();

        $stream = stream();
        $stream->append('Hello');

        $lines = $stream->lines();

        $this->assertIsArray($lines);
        $this->assertGreaterThanOrEqual(1, count($lines));
    }

    public function testWrapsLongLines()
    {
        Prompt::fake();

        $stream = stream();

        // Append a very long string that should wrap
        $longText = str_repeat('word ', 100);
        $stream->append($longText);
        $stream->close();

        $lines = $stream->lines();

        $this->assertGreaterThan(1, count($lines));
    }

    public function testHandlesNewlinesInAppendedText()
    {
        Prompt::fake();

        $stream = stream();
        $stream->append("Line 1\nLine 2\nLine 3");
        $stream->close();

        $this->assertSame("Line 1\nLine 2\nLine 3", $stream->value());

        $lines = $stream->lines();

        $this->assertGreaterThanOrEqual(3, count($lines));
    }

    public function testHandlesEmptyAppends()
    {
        Prompt::fake();

        $stream = stream();
        $stream->append('');
        $stream->append('Hello');
        $stream->append('');
        $stream->close();

        $this->assertSame('Hello', $stream->value());
    }

    public function testCanBeCreatedViaHelperFunction()
    {
        Prompt::fake();

        $stream = stream();

        $this->assertInstanceOf(Stream::class, $stream);
    }
}
