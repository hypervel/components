<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Dogfood\Mcp\Conformance\Fixtures;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class MultipleContentTypesTool extends Tool
{
    protected string $name = 'test_multiple_content_types';

    protected string $description = 'Tests response with multiple content types';

    /**
     * Return text, image and resource link content together.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text('Multiple content types test:'),
            Response::image(Fixtures::IMAGE_BASE64, 'image/png'),
            Response::resourceLink(
                uri: 'test://mixed-content-resource',
                name: 'mixed-content-resource',
                mimeType: 'application/json',
            ),
        ];
    }
}
