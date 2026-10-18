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
        $token = $request->meta()['progressToken'] ?? null;

        foreach ([0, 50, 100] as $progress) {
            if ($progress > 0) {
                usleep(50000);
            }

            if ($token !== null) {
                yield Response::notification('notifications/progress', [
                    'progressToken' => $token,
                    'progress' => $progress,
                    'total' => 100,
                ]);
            }
        }

        yield Response::text('Completed the progress task.');
    }
}
