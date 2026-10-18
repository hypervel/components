<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Prompts;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Prompt;

class SimplePrompt extends Prompt
{
    protected string $name = 'test_simple_prompt';

    protected string $description = 'A simple prompt without arguments';

    /**
     * Return the fixed prompt text.
     */
    public function handle(): Response
    {
        return Response::text('This is a simple prompt for testing.');
    }
}
