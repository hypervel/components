<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default\Concerns;

use Hypervel\Prompts\TimeBasedPrompt;

trait RendersSegments
{
    /**
     * Render the segments with the focused segment highlighted.
     */
    protected function inputRow(TimeBasedPrompt $prompt): string
    {
        $segments = $prompt->segmentValues();

        if (isset($segments[$prompt->focused])) {
            $segments[$prompt->focused] = $this->inverse($segments[$prompt->focused]);
        }

        return $prompt->formatSegments($segments);
    }
}
