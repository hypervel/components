<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ShopNotRegisteredTestResourceServer extends Server
{
    protected array $resources = [
        BuyNotRegisteredTestResource::class,
    ];
}

class BuyNotRegisteredTestResource extends Resource
{
    public static bool $shouldRegister = true;

    /**
     * Determine whether this resource is registered.
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
        BuyNotRegisteredTestResource::$shouldRegister = true;

        parent::tearDown();
    }

    public function testMayAssertThatResourceIsNotRegistered(): void
    {
        BuyNotRegisteredTestResource::$shouldRegister = false;

        $response = ShopNotRegisteredTestResourceServer::resource(BuyNotRegisteredTestResource::class);

        $response->assertNotRegistered();
    }

    public function testMayFailToAssertThatResourceIsNotRegistered(): void
    {
        BuyNotRegisteredTestResource::$shouldRegister = true;

        $response = ShopNotRegisteredTestResourceServer::resource(BuyNotRegisteredTestResource::class);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The resource [' . BuyNotRegisteredTestResource::class . '] is registered.');
        $response->assertNotRegistered();
    }
}
