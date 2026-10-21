<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Prompts\AssertErrorsTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ShopP extends Server
{
    protected array $prompts = [
        BuyPrompt::class,
    ];
}

class BuyPrompt extends Prompt
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
        $response = ShopP::prompt(BuyPrompt::class, ['id' => 1, 'quantity' => 3]);

        $response->assertHasNoErrors();
    }

    public function testMayAssertThatThingsAreOk(): void
    {
        $response = ShopP::prompt(BuyPrompt::class, ['id' => 1, 'quantity' => 3]);

        $response->assertOk();
    }

    public function testMayFailToAssertThatThingsAreOk(): void
    {
        $response = ShopP::prompt(BuyPrompt::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertOk();
    }

    public function testMayAssertValidationFails(): void
    {
        $response = ShopP::prompt(BuyPrompt::class);

        $response->assertHasErrors();
    }

    public function testMayFailToAssertValidationFails(): void
    {
        $response = ShopP::prompt(BuyPrompt::class, ['id' => 1]);

        $this->expectException(AssertionFailedError::class);
        $response->assertHasErrors([
            'The id field is required.',
        ]);
    }

    public function testMayAssertSpecificValidationErrors(): void
    {
        $response = ShopP::prompt(BuyPrompt::class, ['id' => 1]);

        $response->assertHasErrors([
            'The quantity field is required.',
        ]);
    }
}
