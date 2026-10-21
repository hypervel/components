<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server\Prompt;

class GoingToFailPrompt extends Prompt
{
    protected string $description = 'This prompt is going to fail validation';

    /**
     * Validate the required test argument.
     */
    public function handle(Request $request): void
    {
        $request->validate([
            'should_fail' => 'required|boolean',
        ]);
    }
}
