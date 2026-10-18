<?php

declare(strict_types=1);

namespace Hypervel\Ai\Messages;

use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Support\Collection;

class ToolResultMessage extends Message
{
    /** @var Collection<int, ToolResult> */
    public Collection $toolResults;

    /**
     * Create a new text conversation message instance.
     *
     * @param Collection<int, ToolResult> $toolResults
     */
    public function __construct(Collection $toolResults)
    {
        parent::__construct('tool_result', content: null);

        $this->toolResults = $toolResults;
    }
}
