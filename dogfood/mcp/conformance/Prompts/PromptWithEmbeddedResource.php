<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Prompts;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Prompts\Argument;

class PromptWithEmbeddedResource extends Prompt
{
    protected string $name = 'test_prompt_with_embedded_resource';

    protected string $description = 'A prompt that includes an embedded resource';

    /**
     * Return a resource reference with the prompt text.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::resourceLink(
                uri: $request->get('resourceUri'),
                name: 'embedded-resource',
                description: 'Embedded resource content for testing.',
            ),
            Response::text('Please process the embedded resource above.'),
        ];
    }

    /**
     * Define the resource URI argument.
     *
     * @return list<Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('resourceUri', 'URI of the resource to embed', true),
        ];
    }
}
