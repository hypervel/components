<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Support\Utils;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
        yield 'generic ESC control' => ["a\e7b", 'ab'];
        yield 'charset designation' => ["a\e(Bb", 'ab'];
        yield 'DCS payload' => ["a\eP1;2|secret\e\\b", 'ab'];
        yield 'SOS payload' => ["a\eXsecret\e\\b", 'ab'];
        yield 'PM payload' => ["a\e^secret\e\\b", 'ab'];
        yield 'APC payload' => ["a\e_secret\e\\b", 'ab'];
        yield 'BEL recovers malformed DCS' => ["a\ePsecret\x07b", 'ab'];
        yield 'malformed CSI recovers at invalid byte' => ["a\e[12\ntext", "a\ntext"];
        yield 'malformed OSC recovers at the next escape' => ["a\e]8;;http://x\e[31mtext\e[0m", 'atext'];
        yield 'repeated ESC makes forward progress' => ["a\e\e[31mb", 'ab'];
        yield 'unterminated CSI' => ["Ready\e[31", 'Ready'];
        yield 'unterminated OSC' => ["Ready\e]8;;https://hypervel.org", 'Ready'];
        yield 'unterminated charset designation' => ["Ready\e(", 'Ready'];
        yield 'unterminated DCS' => ["Ready\ePsecret", 'Ready'];
        yield 'Symfony named tag' => ['<info>Ready</info>', 'Ready'];
        yield 'Symfony inline tag' => ['<fg=green;options=bold>Ready</>', 'Ready'];
        yield 'Symfony hexadecimal color' => ['<fg=#ff00aa>Ready</>', 'Ready'];
        yield 'Symfony bright and hexadecimal colors' => ['<fg=bright-red;bg=#00ff00>Ready</>', 'Ready'];
        yield 'Symfony combined inline attributes' => ['<fg=blue;bg=bright-yellow;options=bold,underscore>Ready</>', 'Ready'];
        yield 'Symfony hyperlink' => ['<href=https://hypervel.org/docs>Ready</>', 'Ready'];
        yield 'Symfony hyperlink with escaped angle bracket' => ['<href=https://example.com/a\>b>Ready</>', 'Ready'];
        yield 'nested Symfony inline tags' => ['<fg=green>Your action, <fg=yellow>UserName</>?</>', 'Your action, UserName?'];
        yield 'deeply nested Symfony inline tags' => ['<fg=green>A<fg=yellow>B<fg=red>C</>D</>E</>', 'ABCDE'];
        yield 'sibling nested Symfony inline tags' => ['<fg=green>Hello <fg=yellow>World</></> and <fg=red>Foo <fg=blue>Bar</></>', 'Hello World and Foo Bar'];
        yield 'escaped angle brackets' => ['\<info\>Ready\</info\>', '<info>Ready</info>'];
        yield 'unregistered named style remains visible' => ['<fire>Ready</fire>', '<fire>Ready</fire>'];
        yield 'ordinary angle brackets' => ['Use <value> or <other>.', 'Use <value> or <other>.'];
        yield 'plain text' => ['Ready', 'Ready'];
    }

    #[DataProvider('symfonyStyleProvider')]
    public function testStyleTagStrippingAgreesWithSymfony(string $text): void
    {
        $formatter = new OutputFormatter(decorated: false);

        $this->assertSame($formatter->format($text), Utils::stripEscapeSequences($text));
    }

    /**
     * Provide representative Symfony style grammar boundaries.
     *
     * @return iterable<string, array{string}>
     */
    public static function symfonyStyleProvider(): iterable
    {
        yield 'unclosed named style' => ['<info>Ready'];
        yield 'unclosed inline style' => ['<fg=red>Ready'];
        yield 'named styles are case-sensitive' => ['<INFO>Ready</INFO>'];
        yield 'inline styles are case-insensitive' => ['<FG=RED>Ready</>'];
        yield 'escaped opening tag' => ['\<info>Ready'];
        yield 'escaped inline value' => ['<href=https://example.com/a\>b>Ready'];
        yield 'nested inline styles' => ['<fg=green>A<fg=yellow>B</>C</>'];
        yield 'unknown named style' => ['<fire>Ready</fire>'];
    }

    public function testTerminalFormattingPatternCanBeEmbeddedMoreThanOnce(): void
    {
        $pattern = '/\A' . Utils::TERMINAL_FORMATTING_PATTERN
            . 'text' . Utils::TERMINAL_FORMATTING_PATTERN . '\z/';

        $this->assertSame(1, preg_match($pattern, "\e[1mtext\e]8;;https://hypervel.org\e\\"));
    }
}
