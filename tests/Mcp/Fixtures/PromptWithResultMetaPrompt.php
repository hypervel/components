<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Prompt;

class PromptWithResultMetaPrompt extends Prompt
{
    protected string $description = 'Prompt with result-level meta';

    /**
     * Return prompt content and result metadata.
     */
    public function handle(): ResponseFactory
    {
        return Response::make(
            Response::text('Prompt instructions with result meta')->withMeta(['key' => 'value'])
        )->withMeta([
            'prompt_version' => '2.0',
            'last_updated' => '2025-01-01',
        ]);
    }
}
