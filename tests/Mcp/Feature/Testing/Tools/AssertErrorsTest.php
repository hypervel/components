<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertErrorsTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ShopT extends Server
{
    protected array $tools = [
        BuyTool::class,
        EmptyErrorTool::class,
    ];
}

class BuyTool extends Tool
{
    /**
     * Validate a purchase and return its confirmation.
     */
    public function handle(Request $request): string
    {
        $request->validate([
            'id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:5',
        ]);

        return 'Purchase successful!';
    }
}

class EmptyErrorTool extends Tool
{
    /**
     * Return an error without a message.
     */
    public function handle(): Response
    {
        return Response::error('');
    }
}

class AssertErrorsTest extends TestCase
{
    public function testMayAssertValidationPasses(): void
    {
        $response = ShopT::tool(BuyTool::class, ['id' => 1, 'quantity' => 3]);

        $response->assertHasNoErrors();
    }

    public function testMayAssertThatThingsAreOk(): void
    {
        $response = ShopT::tool(BuyTool::class, ['id' => 1, 'quantity' => 3]);

        $response->assertOk();
    }

    public function testMayFailToAssertThatThingsAreOk(): void
    {
        $response = ShopT::tool(BuyTool::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertOk();
    }

    public function testMayAssertValidationFails(): void
    {
        $response = ShopT::tool(BuyTool::class);

        $response->assertHasErrors();
    }

    public function testAnEmptyErrorMessageStillFailsTheSuccessAssertion(): void
    {
        $response = ShopT::tool(EmptyErrorTool::class);
        $response->assertHasErrors();

        $this->expectException(AssertionFailedError::class);
        $response->assertOk();
    }

    public function testMayFailToAssertValidationFails(): void
    {
        $response = ShopT::tool(BuyTool::class, ['id' => 1]);

        $this->expectException(AssertionFailedError::class);
        $response->assertHasErrors([
            'The id field is required.',
        ]);
    }

    public function testMayAssertSpecificValidationErrors(): void
    {
        $response = ShopT::tool(BuyTool::class, ['id' => 1]);

        $response->assertHasErrors([
            'The quantity field is required.',
        ]);
    }
}
