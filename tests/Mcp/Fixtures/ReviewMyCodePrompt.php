<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Prompt;

class ReviewMyCodePrompt extends Prompt
{
    protected string $description = 'Instructions for how to review my code';

    /**
     * Return code review instructions.
     */
    public function handle(): Response
    {
        return Response::text('Here are the instructions on how to review my code');
    }
}
