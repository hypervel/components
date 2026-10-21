<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Resources;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Support\UriTemplate;

class TemplateResource extends Resource implements HasUriTemplate
{
    protected string $name = 'template';

    protected string $mimeType = 'application/json';

    protected string $description = 'A resource template with parameter substitution';

    /**
     * Define the resource's parameterized URI.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('test://template/{id}/data');
    }

    /**
     * Return the substituted resource identifier.
     */
    public function handle(Request $request): Response
    {
        $id = $request->get('id');

        return Response::json([
            'id' => $id,
            'templateTest' => true,
            'data' => "Data for ID: {$id}",
        ]);
    }
}
