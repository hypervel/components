<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\note;

/**
 * @internal
 * @coversNothing
 */
#[BackupStaticProperties(true)]
class NoteTest extends TestCase
{
    public function testRendersNote()
    {
        Prompt::fake();

        note('Hello, World!');

        Prompt::assertOutputContains('Hello, World!');
    }
}
