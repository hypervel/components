<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class MirroredHeaderTool extends Tool
{
    protected string $description = 'This tool mirrors its arguments into Mcp-Param headers';

    /**
     * Return the requested region.
     */
    public function handle(Request $request): Response
    {
        return Response::text("Region: {$request->get('region')}");
    }

    /**
     * Define the tool schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'region' => $schema->string()->required(),
            'replicas' => $schema->integer(),
        ];
    }

    /**
     * Return the tool definition with mirrored header annotations.
     */
    public function toArray(): array
    {
        $tool = parent::toArray();

        $tool['inputSchema']['properties']['region']['x-mcp-header'] = 'Region';
        $tool['inputSchema']['properties']['replicas']['x-mcp-header'] = 'Replicas';

        return $tool;
    }
}
