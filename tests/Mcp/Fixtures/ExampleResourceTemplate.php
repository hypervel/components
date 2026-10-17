<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Support\UriTemplate;

class ExampleResourceTemplate extends Resource implements HasUriTemplate
{
    protected string $description = 'Example resource template for testing';

    protected string $mimeType = 'text/plain';

    /**
     * Get the resource URI template.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://example/{id}');
    }

    /**
     * Return the requested example resource.
     */
    public function handle(Request $request): Response
    {
        $id = $request->get('id');

        return Response::text("Example resource: {$id}");
    }
}
