<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Dogfood\Mcp\Conformance\Fixtures;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class ImageContentTool extends Tool
{
    protected string $name = 'test_image_content';

    protected string $description = 'Tests image content response';

    /**
     * Return the image content fixture.
     */
    public function handle(Request $request): Response
    {
        return Response::image(Fixtures::IMAGE_BASE64, 'image/png');
    }
}
