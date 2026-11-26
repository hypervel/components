<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Tools;

use Generator;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class ProgressTool extends Tool
{
    protected string $name = 'test_tool_with_progress';

    protected string $description = 'Reports progress before returning its result';

    /**
     * Stream progress updates followed by the completed result.
     *
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::progress(0, total: 100);

        usleep(50_000);

        yield Response::progress(50, total: 100);

        usleep(50_000);

        yield Response::progress(100, total: 100);

        yield Response::text('Progress tool completed.');
    }
}
