<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\Title;

class TitleRenderer extends Renderer
{
    /**
     * Render the title.
     */
    public function __invoke(Title $title): string
    {
        return "\033]0;{$title->title}\007";
    }
}
