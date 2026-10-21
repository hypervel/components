<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class EmbeddedResourceTool extends Tool
{
    protected string $name = 'test_embedded_resource';

    protected string $description = 'Tests embedded resource content response';

    /**
     * Return the resource link fixture.
     */
    public function handle(Request $request): Response
    {
        return Response::resourceLink(
            uri: 'test://embedded-resource',
            name: 'embedded-resource',
            mimeType: 'text/plain',
            description: 'This is an embedded resource content.',
        );
    }
}
