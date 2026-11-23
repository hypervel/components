<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class StreamingElicitationTool extends Tool
{
    protected string $name = 'test_streaming_elicitation';

    protected string $description = 'Tests that the response stream never carries independent requests';

    /**
     * Complete without sending requests on the response stream.
     */
    public function handle(Request $request): Response
    {
        return Response::text('Streaming elicitation tool completed.');
    }
}
