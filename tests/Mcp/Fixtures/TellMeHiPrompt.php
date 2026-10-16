<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Prompt;

class TellMeHiPrompt extends Prompt
{
    protected string $description = 'Instructions for how to tell me hi';

    /**
     * Return greeting instructions.
     */
    public function handle(): Response
    {
        return Response::text('Here are the instructions on how to tell me hi');
    }
}
