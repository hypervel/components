<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Themes\Default;

use Hypervel\Prompts\Stream;

class StreamRenderer extends Renderer
{
    /**
     * Render the stream.
     */
    public function __invoke(Stream $stream): string
    {
        foreach ($stream->lines() as $line) {
            $this->line(" {$line}");
        }

        return (string) $this;
    }
}
