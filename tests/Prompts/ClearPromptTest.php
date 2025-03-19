<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\clear;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class ClearPromptTest extends TestCase
{
    public function testPromptClear()
    {
        Prompt::fake();

        clear();

        Prompt::assertOutputContains("\033[H\033[J");
    }
}
