<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Prompts\AssertRegisteredTest;

use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;

class GarageListPromptsServer extends Server
{
    public int $defaultPaginationLength = 1;

    protected array $prompts = [
        OilChangePrompt::class,
        TireRotationPrompt::class,
    ];
}

class OilChangePrompt extends Prompt
{
    public static bool $shouldRegister = true;

    protected string $name = 'garage/oil-change';

    /**
     * Determine whether this prompt is registered.
     */
    public function shouldRegister(): bool
    {
        return static::$shouldRegister;
    }

    /**
     * Return the oil change confirmation.
     */
    public function handle(): string
    {
        return 'Oil changed.';
    }
}

class TireRotationPrompt extends Prompt
{
    protected string $name = 'garage/tire-rotation';

    /**
     * Return the tire rotation confirmation.
     */
    public function handle(): string
    {
        return 'Tires rotated.';
    }
}

class AssertRegisteredTest extends TestCase
{
    /**
     * Restore the fixture's registration state.
     */
    protected function tearDown(): void
    {
        OilChangePrompt::$shouldRegister = true;

        parent::tearDown();
    }

    public function testMayAssertThatPromptsAreRegisteredOnAServer(): void
    {
        OilChangePrompt::$shouldRegister = true;

        GarageListPromptsServer::prompts()->assertRegistered([
            OilChangePrompt::class,
            TireRotationPrompt::class,
        ]);
    }

    public function testMayFailToAssertThatPromptsAreRegisteredOnAServer(): void
    {
        OilChangePrompt::$shouldRegister = false;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected class [' . OilChangePrompt::class . '] was not found in the response content.');
        GarageListPromptsServer::prompts()->assertRegistered([
            TireRotationPrompt::class,
            OilChangePrompt::class,
        ]);
    }

    public function testMayAssertThatPromptsAreNotRegisteredOnAServer(): void
    {
        OilChangePrompt::$shouldRegister = false;

        GarageListPromptsServer::prompts()->assertRegistered([
            TireRotationPrompt::class,
        ])->assertNotRegistered([
            OilChangePrompt::class,
        ]);
    }

    public function testMayFailToAssertThatPromptsAreNotRegisteredOnAServer(): void
    {
        OilChangePrompt::$shouldRegister = true;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The expected class [' . OilChangePrompt::class . '] was found in the response content.');
        GarageListPromptsServer::prompts()->assertRegistered([
            TireRotationPrompt::class,
        ])->assertNotRegistered([
            OilChangePrompt::class,
        ]);
    }
}
