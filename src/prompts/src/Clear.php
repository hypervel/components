<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Hypervel\Prompts\Support\Utils;

class Clear extends Prompt
{
    /**
     * Clear the terminal.
     */
    public function prompt(): bool
    {
        // Fill the previous newline count so subsequent prompts won't add padding.
        static::output()->write(PHP_EOL . PHP_EOL);

        $frame = $this->renderTheme();

        $this->writeDirectly(static::output()->isDecorated() ? $frame : Utils::stripTerminalControls($frame));

        return true;
    }

    /**
     * Clear the terminal.
     */
    public function display(): void
    {
        $this->prompt();
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): bool
    {
        return true;
    }
}
