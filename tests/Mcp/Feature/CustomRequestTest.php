<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\CustomRequestTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;

class MyCustomRequest extends Request
{
    /**
     * Get the custom request message.
     */
    public function myMethod(): string
    {
        return $this->string('message')->value();
    }
}

class LaptopShopServer extends Server
{
    protected array $prompts = [
        AskLaptop::class,
    ];

    protected array $tools = [
        BuyLaptop::class,
    ];

    protected array $resources = [
        LaptopGuidelines::class,
    ];
}

class AskLaptop extends Prompt
{
    /**
     * Return the custom prompt message.
     */
    public function handle(MyCustomRequest $request): string
    {
        return $request->myMethod();
    }
}

class BuyLaptop extends Tool
{
    /**
     * Return the custom tool message.
     */
    public function handle(MyCustomRequest $request): string
    {
        return $request->myMethod();
    }
}

class LaptopGuidelines extends Resource
{
    /**
     * Return the custom resource message.
     */
    public function handle(MyCustomRequest $request): string
    {
        return $request->myMethod();
    }
}

class CustomRequestTest extends TestCase
{
    public function testCanUseTheCustomRequestClassOnPrompts(): void
    {
        $response = LaptopShopServer::prompt(AskLaptop::class, [
            'message' => 'Hello, Prompt!',
        ]);

        $response->assertSee('Hello, Prompt!');
    }

    public function testCanUseTheCustomRequestClassOnTools(): void
    {
        $response = LaptopShopServer::tool(BuyLaptop::class, [
            'message' => 'Hello, Tool!',
        ]);

        $response->assertSee('Hello, Tool!');
    }

    public function testCanUseTheCustomRequestClassOnResources(): void
    {
        $response = LaptopShopServer::resource(LaptopGuidelines::class, [
            'message' => 'Hello, Resource!',
        ]);

        $response->assertSee('Hello, Resource!');
    }
}
