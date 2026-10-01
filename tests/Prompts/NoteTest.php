<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Note;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\intro;
use function Hypervel\Prompts\note;

class NoteTest extends TestCase
{
    public function testRendersNote(): void
    {
        Prompt::fake();

        note('Hello, World!');

        Prompt::assertOutputContains('Hello, World!');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);
        $invoked = false;

        Note::fallbackUsing(function (Note $note) use (&$invoked): bool {
            $invoked = true;
            $this->assertSame('Hello, World!', $note->message);

            return true;
        });

        $result = (new Note('Hello, World!'))->display();

        $this->assertNull($result);
        $this->assertTrue($invoked);
    }

    public function testPadsIntroLinesToTheWidestDisplayedLine(): void
    {
        Prompt::fake();

        intro("Deploying\n日本語\n\e[1mBold\e[0m");

        $widths = array_map(
            fn (string $line): int => mb_strwidth($line),
            array_values(array_filter(explode(PHP_EOL, Prompt::strippedContent()))),
        );

        $this->assertSame([12, 12, 12], $widths);
    }
}
