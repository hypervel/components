<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Note;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\note;

/**
 * @internal
 * @coversNothing
 */
class NoteTest extends TestCase
{
    public function testRendersNote()
    {
        Prompt::fake();

        note('Hello, World!');

        Prompt::assertOutputContains('Hello, World!');
    }

    public function testCanFallBack()
    {
        Prompt::fallbackWhen(true);

        Note::fallbackUsing(function (Note $note) {
            $this->assertSame('Hello, World!', $note->message);

            return true;
        });

        $result = (new Note('Hello, World!'))->display();

        $this->assertNull($result);
    }
}
