<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Support\Utils;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class UtilsTest extends TestCase
{
    public function testItWritesAnEntirePayloadToAStream(): void
    {
        $stream = fopen('php://temp', 'w+');
        $payload = str_repeat('prompt output ', 1024);

        Utils::writeAll($stream, $payload);
        rewind($stream);

        $this->assertSame($payload, stream_get_contents($stream));

        fclose($stream);
    }

    #[DataProvider('escapeSequencesProvider')]
    public function testItStripsEscapeSequencesAndStyleTags(string $text, string $expected): void
    {
        $this->assertSame($expected, Utils::stripEscapeSequences($text));
    }

    /**
     * Provide terminal escape sequences and style tags.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function escapeSequencesProvider(): iterable
    {
        yield 'SGR' => ["\e[31mRed\e[0m", 'Red'];
        yield 'colon-parameter SGR' => ["\e[4:3mCurly\e[0m", 'Curly'];
        yield 'private-parameter CSI' => ["\e[>4;2mModified\e[0m", 'Modified'];
        yield 'CSI with an intermediate byte' => ["\e[5 qCursor", 'Cursor'];
        yield 'non-SGR CSI followed by visible m' => ["\e[2Kmaximum", 'maximum'];
        yield 'BEL-terminated OSC title' => ["\e]0;Hypervel\x07Ready", 'Ready'];
        yield 'ST-terminated OSC hyperlink' => ["\e]8;;https://hypervel.org\e\\Hypervel\e]8;;\e\\", 'Hypervel'];
        yield 'unterminated CSI' => ["Ready\e[31", "Ready\e[31"];
        yield 'unterminated OSC' => ["Ready\e]8;;https://hypervel.org", "Ready\e]8;;https://hypervel.org"];
        yield 'Symfony named tag' => ['<info>Ready</info>', 'Ready'];
        yield 'Symfony inline tag' => ['<fg=green;options=bold>Ready</>', 'Ready'];
        yield 'plain text' => ['Ready', 'Ready'];
    }
}
