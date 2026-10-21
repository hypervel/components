<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources\AssertErrorsTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ShopR extends Server
{
    protected array $resources = [
        BuyResource::class,
    ];
}

class BuyResource extends Resource
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

class AssertErrorsTest extends TestCase
{
    public function testMayAssertValidationPasses(): void
    {
        $response = ShopR::resource(BuyResource::class, ['id' => 1, 'quantity' => 3]);

        $response->assertHasNoErrors();
    }

    public function testMayAssertThatThingsAreOk(): void
    {
        $response = ShopR::resource(BuyResource::class, ['id' => 1, 'quantity' => 3]);

        $response->assertOk();
    }

    public function testMayFailToAssertThatThingsAreOk(): void
    {
        $response = ShopR::resource(BuyResource::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertOk();
    }

    public function testMayAssertValidationFails(): void
    {
        $response = ShopR::resource(BuyResource::class);

        $response->assertHasErrors();
    }

    public function testMayFailToAssertValidationFails(): void
    {
        $response = ShopR::resource(BuyResource::class, ['id' => 1]);

        $this->expectException(AssertionFailedError::class);
        $response->assertHasErrors([
            'The id field is required.',
        ]);
    }

    public function testMayAssertSpecificValidationErrors(): void
    {
        $response = ShopR::resource(BuyResource::class, ['id' => 1]);

        $response->assertHasErrors([
            'The quantity field is required.',
        ]);
    }
}
