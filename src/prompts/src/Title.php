<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Hypervel\Prompts\Support\Utils;

class Title extends Prompt
{
    /**
     * Create a new Title instance.
     */
    public function __construct(public string $title)
    {
    }

    /**
     * Update the title of the terminal.
     */
    public function prompt(): bool
    {
        $frame = $this->renderTheme();

        $this->writeDirectly(static::output()->isDecorated() ? $frame : Utils::stripTerminalControls($frame));

        return true;
    }

    /**
     * Update the title of the terminal.
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
