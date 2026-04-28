<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;

use function Hypervel\Prompts\title;

class TitlePromptTest extends TestCase
{
    public function testUpdatesTheTitle()
    {
        Prompt::fake();

        title('Hello, World!');

        Prompt::assertOutputContains("\033]0;Hello, World!\007");
    }
}
