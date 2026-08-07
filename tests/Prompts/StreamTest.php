<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\Stream;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

use function Hypervel\Prompts\stream;

class StreamTest extends TestCase
{
    public function testUndecoratedStreamWritesExactChunksWithoutTerminalWork(): void
    {
        Prompt::fake();
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));
        Prompt::terminal()->shouldNotReceive('cols'); // @phpstan-ignore-line
        Prompt::terminal()->shouldNotReceive('supportsTrueColor'); // @phpstan-ignore-line
        $stream = new Stream;

        $stream->append('Hello, ');
        $stream->append("World!\nDone.");

        $this->assertSame("Hello, World!\nDone.", Prompt::content());
        $this->assertSame("Hello, World!\nDone.", $stream->value());
        $this->assertSame(['Hello, World!', 'Done.'], $stream->lines());

        $stream->close();
        $content = Prompt::content();
        unset($stream);

        $this->assertSame($content, Prompt::content());
        $this->assertStringNotContainsString("\e", Prompt::content());
    }

    public function testCloseRestoresCursorBeforeRetainedStreamIsDestroyed(): void
    {
        Prompt::fake();
        $stream = new Stream;

        $stream->append('Hello');
        $stream->close();

        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );

        $content = Prompt::content();
        unset($stream);

        $this->assertSame($content, Prompt::content());
    }

    #[DataProvider('trueColorProvider')]
    public function testAbandonedDecoratedStreamRestoresCursorImmediately(bool $supportsTrueColor): void
    {
        Prompt::fake();
        Prompt::terminal()->shouldReceive('supportsTrueColor')->once()->andReturn($supportsTrueColor); // @phpstan-ignore-line

        if ($supportsTrueColor) {
            Prompt::terminal()->shouldReceive('foregroundColor')->once()->andReturn([204, 204, 204]); // @phpstan-ignore-line
            Prompt::terminal()->shouldReceive('backgroundColor')->once()->andReturn([0, 0, 0]); // @phpstan-ignore-line
        }

        $garbageCollectionEnabled = gc_enabled();
        gc_disable();

        try {
            $stream = new Stream;
            $reference = WeakReference::create($stream);

            $this->assertTrue(
                (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
            );

            unset($stream);

            $this->assertNull($reference->get());
            $this->assertFalse(
                (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
            );
        } finally {
            if ($garbageCollectionEnabled) {
                gc_enable();
            }
        }
    }

    /**
     * Provide terminal true-color support modes.
     *
     * @return iterable<string, array{bool}>
     */
    public static function trueColorProvider(): iterable
    {
        yield 'fallback colors' => [false];
        yield 'true colors' => [true];
    }

    public function testColorProbeFailureDoesNotAcquireCursorOwnership(): void
    {
        Prompt::fake();
        $failure = new RuntimeException('unable to query terminal colors');

        Prompt::terminal()->shouldReceive('supportsTrueColor')->once()->andReturnTrue(); // @phpstan-ignore-line
        Prompt::terminal()->shouldReceive('foregroundColor')->once()->andThrow($failure); // @phpstan-ignore-line

        try {
            new Stream;

            $this->fail('Expected stream construction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertStringNotContainsString("\e[?25l", Prompt::content());
        $this->assertFalse(
            (new ReflectionProperty(Prompt::class, 'cursorHidden'))->getValue(),
        );
    }

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
