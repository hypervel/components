<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\RequestMetaTest;

use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;

class RequestMetaServer extends Server
{
    protected array $tools = [
        CapabilityAwareTool::class,
    ];
}

class CapabilityAwareTool extends Tool
{
    /**
     * Return the incoming request capabilities and progress token presence.
     */
    public function handle(Request $request): ResponseFactory
    {
        $meta = $request->meta() ?? [];

        return Response::structured([
            'capabilities' => $meta[MetaKey::CLIENT_CAPABILITIES->value] ?? null,
            'hasProgressToken' => array_key_exists('progressToken', $meta),
        ]);
    }
}

class CapabilityAwareCall extends Tool
{
    /**
     * Wrap a tool call with custom metadata.
     */
    public function __construct(private readonly Tool $tool)
    {
    }

    /**
     * Return the wrapped tool name.
     */
    public function name(): string
    {
        return $this->tool->name();
    }

    /**
     * Include custom metadata on the tool call payload.
     */
    public function toMethodCall(): array
    {
        return [
            ...$this->tool->toMethodCall(),
            '_meta' => [MetaKey::CLIENT_CAPABILITIES->value => ['extensions' => ['io.modelcontextprotocol/ui' => []]]],
        ];
    }
}

class RequestMetaTest extends TestCase
{
    public function testKeepsTheMetaFromTheMethodCallAlongsideTheProgressToken(): void
    {
        RequestMetaServer::tool(new CapabilityAwareCall(new CapabilityAwareTool))
            ->assertStructuredContent([
                'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => []]],
                'hasProgressToken' => true,
            ]);
    }

    public function testDeclaresNoClientCapabilitiesByDefault(): void
    {
        RequestMetaServer::tool(CapabilityAwareTool::class)
            ->assertStructuredContent([
                'capabilities' => [],
                'hasProgressToken' => true,
            ]);
    }

    public function testSendsTheClientCapabilitiesTheTestDeclares(): void
    {
        RequestMetaServer::withClientCapabilities(['sampling' => ['tools' => []]])
            ->tool(CapabilityAwareTool::class)
            ->assertStructuredContent([
                'capabilities' => ['sampling' => ['tools' => []]],
                'hasProgressToken' => true,
            ]);
    }

    public function testPrefersTheMethodCallMetaOverTheDeclaredClientCapabilities(): void
    {
        RequestMetaServer::withClientCapabilities(['sampling' => []])
            ->tool(new CapabilityAwareCall(new CapabilityAwareTool))
            ->assertStructuredContent([
                'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => []]],
                'hasProgressToken' => true,
            ]);
    }
}
