<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Support\TaskFrame;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class TaskFrameTest extends TestCase
{
    public function testEncodesExactBinaryFrameBytes(): void
    {
        $this->assertSame("\x01\x00\x00\x00\x03a\n\0", TaskFrame::encode(null, "a\n\0"));
    }

    #[DataProvider('messageTypes')]
    public function testRoundTripsEveryMessageType(?string $type): void
    {
        $payload = "first\n\nsecond\0tail";
        $decoder = new TaskFrame;

        $decoder->append(TaskFrame::encode($type, $payload));

        $this->assertSame(['type' => $type, 'payload' => $payload], $decoder->next());
        $this->assertNull($decoder->next());
        $decoder->finish();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function messageTypes(): array
    {
        return [
            'line' => [null],
            'success' => ['success'],
            'warning' => ['warning'],
            'error' => ['error'],
            'label' => ['label'],
            'sublabel' => ['sublabel'],
            'reset' => ['reset'],
            'partial' => ['partial'],
            'partial commit' => ['commitpartial'],
        ];
    }

    public function testDecodesSplitHeadersAndPayloads(): void
    {
        $frame = TaskFrame::encode('partial', "chunk\0\n");
        $decoder = new TaskFrame;

        foreach (str_split($frame) as $index => $byte) {
            $decoder->append($byte);

            if ($index < strlen($frame) - 1) {
                $this->assertNull($decoder->next());
            }
        }

        $this->assertSame(
            ['type' => 'partial', 'payload' => "chunk\0\n"],
            $decoder->next(),
        );
        $decoder->finish();
    }

    public function testDecodesMultipleFramesFromOneRead(): void
    {
        $decoder = new TaskFrame;
        $decoder->append(
            TaskFrame::encode(null, 'line')
            . TaskFrame::encode('commitpartial', '')
            . TaskFrame::encode('reset', "\x01"),
        );

        $this->assertSame(['type' => null, 'payload' => 'line'], $decoder->next());
        $this->assertSame(['type' => 'commitpartial', 'payload' => ''], $decoder->next());
        $this->assertSame(['type' => 'reset', 'payload' => "\x01"], $decoder->next());
        $this->assertNull($decoder->next());
        $decoder->finish();
    }

    public function testRejectsAnUnknownEncodedType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown task message type [debug].');

        TaskFrame::encode('debug', 'message');
    }

    public function testRejectsAnUnknownDecodedType(): void
    {
        $decoder = new TaskFrame;
        $decoder->append("\xff\x00\x00\x00\x00");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown task message type [255].');

        $decoder->next();
    }

    public function testRejectsAnIncompleteFrameAtEof(): void
    {
        $decoder = new TaskFrame;
        $decoder->append(substr(TaskFrame::encode('success', 'done'), 0, -1));

        $this->assertNull($decoder->next());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The prompt renderer received an incomplete task message.');

        $decoder->finish();
    }

    public function testResetDiscardsAnIncompleteFrame(): void
    {
        $decoder = new TaskFrame;
        $decoder->append("\x01\x00");

        $decoder->reset();
        $decoder->finish();

        $this->assertNull($decoder->next());
    }
}
