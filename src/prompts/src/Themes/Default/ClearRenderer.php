<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

class ClearRenderer extends Renderer
{
    /**
     * Clear the terminal.
     */
    public function __invoke(): string
    {
        return "\033[H\033[J";
    }
}
