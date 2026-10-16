<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class ResourceLinkTool extends Tool
{
    protected string $description = 'Returns a resource link to a generated report.';

    /**
     * Return a generated report link.
     */
    public function handle(Request $request): Response
    {
        return Response::resourceLink(
            uri: 'file:///reports/monthly.pdf',
            name: 'monthly-report',
            mimeType: 'application/pdf',
            title: 'Monthly Report',
            description: 'Sales rollup by region.',
            size: 2048,
            annotations: [
                'audience' => ['user'],
                'priority' => 0.9,
                'lastModified' => '2026-05-07T12:00:00Z',
            ],
        );
    }
}
