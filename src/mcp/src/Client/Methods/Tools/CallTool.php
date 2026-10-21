<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Methods\Tools;

use Hypervel\Mcp\Client\Contracts\Method;
use Hypervel\Mcp\Client\Contracts\MirrorsParameters;
use Hypervel\Mcp\Client\Protocol;
use Hypervel\Mcp\Client\Schema\ToolResult;
use Hypervel\Mcp\Support\MirroredParameters;

/**
 * @implements Method<ToolResult>
 */
class CallTool implements Method, MirrorsParameters
{
    /**
     * Create a tool call request.
     *
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        protected string $name,
        protected array $arguments = [],
        protected ?MirroredParameters $mirroredParameters = null,
    ) {
    }

    /**
     * Get the headers mirroring tool arguments.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        return $this->mirroredParameters?->headers($this->arguments) ?? [];
    }

    /**
     * Get the tool call method name.
     */
    public function method(): string
    {
        return 'tools/call';
    }

    /**
     * Get the tool name and arguments.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return [
            'name' => $this->name,
            'arguments' => (object) $this->arguments,
        ];
    }

    /**
     * Call the tool and hydrate its result.
     */
    public function handle(Protocol $protocol): ToolResult
    {
        return ToolResult::from($protocol->dispatch($this));
    }
}
