<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class CustomHeaderTool extends Tool
{
    protected string $name = 'test_custom_header';

    protected string $description = 'Tests custom Mcp-Param header mirroring';

    /**
     * Return the mirrored region.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Region: {$request->get('region')}");
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'region' => $schema->string()->description('Deployment region')->required(),
        ];
    }

    /**
     * Mark the region parameter for header mirroring.
     */
    public function toArray(): array
    {
        $tool = parent::toArray();

        $tool['inputSchema']['properties']['region']['x-mcp-header'] = 'Region';

        return $tool;
    }
}
