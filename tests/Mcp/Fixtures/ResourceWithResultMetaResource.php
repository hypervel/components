<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Resource;

class ResourceWithResultMetaResource extends Resource
{
    /**
     * Return the resource description.
     */
    public function description(): string
    {
        return 'Resource with result-level meta';
    }

    /**
     * Return resource content and result metadata.
     */
    public function handle(): ResponseFactory
    {
        return Response::make(
            Response::text('Resource content with result meta')
        )->withMeta([
            'last_modified' => '2025-01-01',
            'version' => '1.0',
        ]);
    }

    /**
     * Return the resource URI.
     */
    public function uri(): string
    {
        return 'file://resources/with-result-meta.txt';
    }

    /**
     * Return the resource MIME type.
     */
    public function mimeType(): string
    {
        return 'text/plain';
    }
}
