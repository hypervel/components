<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Support\UriTemplate;

class UriTemplateUserFileResource extends Resource implements HasUriTemplate
{
    /**
     * Get the user file URI template.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://users/{userId}/files/{fileId}');
    }

    /**
     * Return the resolved user file arguments.
     */
    public function handle(Request $request): Response
    {
        return Response::json([
            'userId' => $request->get('userId'),
            'fileId' => $request->get('fileId'),
            'uri' => $request->uri(),
        ]);
    }
}
