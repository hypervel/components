<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Prompts\Output\BufferedConsoleOutput;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ThemesTest extends TestCase
{
    public function testPromptFakeUsesDecoratedOutput(): void
    {
        Prompt::fake();
        Prompt::addTheme('testing', [ThemesTestPrompt::class => ThemesTestRenderer::class]);
        Prompt::theme('testing');

        $this->assertSame("\e[2Kmaximum", (new ThemesTestPrompt)->renderForTest());
    }

    public function testUndecoratedOutputStripsTerminalControlSequencesFromRenderedFrames(): void
    {
        Prompt::setOutput(new BufferedConsoleOutput(decorated: false));
        Prompt::addTheme('testing', [ThemesTestPrompt::class => ThemesTestRenderer::class]);
        Prompt::theme('testing');

        $this->assertSame('maximum', (new ThemesTestPrompt)->renderForTest());
    }

    public function testMissingRendererNamesTheConcretePromptClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt renderer for [' . ThemesTestPrompt::class . '] not found.');

        (new ThemesTestPrompt)->renderForTest();
    }
}

class ThemesTestPrompt extends Prompt
{
    /**
     * Get the prompt value.
     */
    public function value(): mixed
    {
        return null;
    }

    /**
     * Render the prompt for testing.
     */
    public function renderForTest(): string
    {
        return $this->renderTheme();
    }
}

class ThemesTestRenderer
{
    /**
     * Create a new renderer instance.
     */
    public function __construct(protected Prompt $prompt)
    {
    }

    /**
     * Render the prompt.
     */
    public function __invoke(): string
    {
        return "\e[2Kmaximum";
    }
}
