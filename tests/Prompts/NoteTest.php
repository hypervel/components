<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\note;

/**
 * @backupStaticProperties enabled
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
}
