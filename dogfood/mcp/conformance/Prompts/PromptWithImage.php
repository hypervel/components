<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Prompts;

use Hypervel\Dogfood\Mcp\Conformance\Fixtures;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Prompt;

class PromptWithImage extends Prompt
{
    protected string $name = 'test_prompt_with_image';

    protected string $description = 'A prompt that includes image content';

    /**
     * Return an image with the prompt text.
     *
     * @return array<int, Response>
     */
    public function handle(): array
    {
        return [
            Response::image(base64_decode(Fixtures::IMAGE_BASE64, true), 'image/png'),
            Response::text('Please analyze the image above.'),
        ];
    }
}
