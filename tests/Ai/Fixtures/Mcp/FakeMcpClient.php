<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Mcp;

class FakeMcpClient
{
    public array $results = [];

    public array $toolCalls = [];

    public int $connects = 0;

    public int $disconnects = 0;

    /**
     * Connect the client.
     */
    public function connect(): static
    {
        ++$this->connects;

        return $this;
    }

    /**
     * Disconnect the client.
     */
    public function disconnect(): void
    {
        ++$this->disconnects;
    }

    /**
     * Call a tool and return its configured result.
     *
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments = []): FakeMcpToolResult
    {
        $this->toolCalls[] = ['name' => $name, 'arguments' => $arguments];

        return $this->results[$name];
    }
}
