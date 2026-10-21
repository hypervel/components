<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ShopNotRegisteredTestServer extends Server
{
    protected array $tools = [
        BuyNotRegisteredTestTool::class,
    ];
}

class BuyNotRegisteredTestTool extends Tool
{
    public static bool $shouldRegister = true;

    /**
     * Determine whether this tool is registered.
     */
    public function shouldRegister(Request $request): bool
    {
        return static::$shouldRegister;
    }

    /**
     * Return the purchase confirmation.
     */
    public function handle(Request $request): string
    {
        return 'Purchase successful!';
    }
}

class AssertNotRegisteredTest extends TestCase
{
    /**
     * Restore the fixture's registration state.
     */
    protected function tearDown(): void
    {
        BuyNotRegisteredTestTool::$shouldRegister = true;

        parent::tearDown();
    }

    public function testMayAssertThatToolIsNotRegistered(): void
    {
        BuyNotRegisteredTestTool::$shouldRegister = false;

        $response = ShopNotRegisteredTestServer::tool(BuyNotRegisteredTestTool::class);

        $response->assertNotRegistered();
    }

    public function testMayFailToAssertThatToolIsNotRegistered(): void
    {
        BuyNotRegisteredTestTool::$shouldRegister = true;

        $response = ShopNotRegisteredTestServer::tool(BuyNotRegisteredTestTool::class);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The tool [' . BuyNotRegisteredTestTool::class . '] is registered.');
        $response->assertNotRegistered();
    }
}
