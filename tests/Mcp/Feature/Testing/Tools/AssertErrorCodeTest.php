<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertErrorCodeTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Testing\TestResponse;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ErrorCodeServer extends Server
{
    protected array $tools = [
        ErrorCodeTool::class,
    ];
}

class ErrorCodeTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }
}

class UnregisteredErrorCodeTool extends ErrorCodeTool
{
}

class AssertErrorCodeTest extends TestCase
{
    public function testAssertsTheJsonRpcErrorCode(): void
    {
        ErrorCodeServer::tool(UnregisteredErrorCodeTool::class)->assertErrorCode(-32602);
    }

    public function testAssertsTheJsonRpcErrorData(): void
    {
        $response = new TestResponse(new ErrorCodeTool, JsonRpcResponse::error(1, -32021, 'Missing capability.', [
            'requiredCapabilities' => ['sampling' => (object) []],
        ]));

        $response->assertErrorCode(-32021, ['requiredCapabilities' => ['sampling' => []]])
            ->assertErrorCode(-32021, ['requiredCapabilities' => ['sampling' => (object) []]]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The response does not carry the expected error data.');

        $response->assertErrorCode(-32021, ['requiredCapabilities' => ['roots' => []]]);
    }

    public function testFailsWhenTheErrorCodeDiffers(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The response does not carry the expected error code.');

        ErrorCodeServer::tool(UnregisteredErrorCodeTool::class)->assertErrorCode(-32601);
    }

    public function testFailsWhenTheResponseIsNotAnError(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The response is not a JSON-RPC error.');

        ErrorCodeServer::tool(ErrorCodeTool::class)->assertErrorCode(-32602);
    }
}
