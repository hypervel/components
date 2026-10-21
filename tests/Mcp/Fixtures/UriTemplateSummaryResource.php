<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Support\UriTemplate;

class UriTemplateSummaryResource extends Resource implements HasUriTemplate
{
    /**
     * Get the summary URI template.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://summary/{id}');
    }

    /**
     * Return the resolved summary arguments.
     */
    public function handle(Request $request): Response
    {
        $request->validate(['id' => 'required|string']);

        return Response::json([
            'id' => $request->get('id'),
            'uri' => $request->uri(),
        ]);
    }
}
