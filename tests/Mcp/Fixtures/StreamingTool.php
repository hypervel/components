<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Generator;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Tool;

class StreamingTool extends Tool
{
    protected string $description = 'A tool that streams multiple responses.';

    /**
     * Define the streaming arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()
                ->description('Number of messages to stream.')
                ->required(),
        ];
    }

    /**
     * Emit progress and a final result.
     */
    public function handle(Request $request): Generator
    {
        $count = $request->integer('count', 2);

        for ($i = 1; $i <= $count; ++$i) {
            yield Response::notification('stream/progress', ['progress' => $i / $count * 100, 'message' => "Processing item {$i} of {$count}"]);
        }

        yield Response::text("Finished streaming {$count} messages.");
    }
}
