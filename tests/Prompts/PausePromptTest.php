<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Key;
use Hypervel\Prompts\PausePrompt;
use Hypervel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

use function Hypervel\Prompts\pause;

/**
 * @backupStaticProperties enabled
 * @internal
 * @coversNothing
 */
class PausePromptTest extends TestCase
{
    public function testContinuesAfterEnter(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = pause();

        $this->assertTrue($result);
        Prompt::assertOutputContains('Press enter to continue...');
    }

    public function testAllowsMessageToBeChanged(): void
    {
        Prompt::fake([Key::ENTER]);

        $result = pause('Read and then press enter...');

        $this->assertTrue($result);
        Prompt::assertOutputContains('Read and then press enter...');
    }

    public function testCanFallBack(): void
    {
        Prompt::fallbackWhen(true);

        PausePrompt::fallbackUsing(function (PausePrompt $prompt) {
            $this->assertSame('Press enter to continue...', $prompt->message);
            return true;
        });

        $result = pause();

        $this->assertTrue($result);
    }

    public function testDoesNotRenderWhenNonInteractive(): void
    {
        Prompt::fake();
        Prompt::interactive(false);

        $result = pause('This should not be rendered');

        $this->assertFalse($result);
        Prompt::assertOutputDoesntContain('This should not be rendered');
    }
}
