<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Prompts;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class ShopNotRegisteredTestPromptServer extends Server
{
    protected array $prompts = [
        BuyNotRegisteredTestPrompt::class,
    ];
}

class BuyNotRegisteredTestPrompt extends Prompt
{
    public static bool $shouldRegister = true;

    /**
     * Determine whether this prompt is registered.
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
        BuyNotRegisteredTestPrompt::$shouldRegister = true;

        parent::tearDown();
    }

    public function testMayAssertThatPromptIsNotRegistered(): void
    {
        BuyNotRegisteredTestPrompt::$shouldRegister = false;

        $response = ShopNotRegisteredTestPromptServer::prompt(BuyNotRegisteredTestPrompt::class);

        $response->assertNotRegistered();
    }

    public function testMayFailToAssertThatPromptIsNotRegistered(): void
    {
        BuyNotRegisteredTestPrompt::$shouldRegister = true;

        $response = ShopNotRegisteredTestPromptServer::prompt(BuyNotRegisteredTestPrompt::class);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The prompt [' . BuyNotRegisteredTestPrompt::class . '] is registered.');
        $response->assertNotRegistered();
    }
}
