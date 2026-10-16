<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertSeeTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class HotelT extends Server
{
    protected array $tools = [
        BookingTool::class,
    ];
}

class BookingTool extends Tool
{
    /**
     * Return the booking result for the requested date.
     */
    public function handle(Request $request): Response|array|string
    {
        $date = $request->date('date');

        if ($date?->isPast()) {
            return Response::error('The booking date cannot be in the past.');
        }

        if ($date?->year === 2999) {
            return [
                'You must be joking! That date is too far in the future.',
                'Please select a more reasonable date.',
            ];
        }

        return 'Your booking is confirmed!';
    }

    /**
     * Determine whether this tool is available for the request.
     */
    public function shouldRegister(Request $request): bool
    {
        return $request->boolean('register', true);
    }
}

class AssertSeeTest extends TestCase
{
    public function testMayAssertThatTextIsSeenWhenReturningStringContent(): void
    {
        $response = HotelT::tool(BookingTool::class);

        $response->assertSee('Your booking is confirmed!')
            ->assertDontSee('The booking date cannot be in the past.')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testMayAssertThatTextIsSeenWhenProvidingArguments(): void
    {
        $response = HotelT::tool(BookingTool::class, ['date' => now()->addDay()->toDateString()]);

        $response->assertSee('Your booking is confirmed!')
            ->assertDontSee('The booking date cannot be in the past.')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testMayAssertThatTextIsSeenWhenProvidingArgumentsThatAreWrong(): void
    {
        $response = HotelT::tool(BookingTool::class, ['date' => now()->subDay()->toDateString()]);

        $response
            ->assertSee('The booking date cannot be in the past.')
            ->assertDontSee('Your booking is confirmed!')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testFailsToAssertThatTextIsSeenWhenNotPresent(): void
    {
        $response = HotelT::tool(BookingTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertSee('This text is not present');
    }

    public function testFailsToAssertThatTextIsNotSeenWhenItIsPresent(): void
    {
        $response = HotelT::tool(BookingTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertDontSee('Your booking is confirmed!');
    }

    public function testMayAssertThatTextIsSeenWhenReturningArrayContent(): void
    {
        $response = HotelT::tool(BookingTool::class, ['date' => '2999-01-01']);

        $response
            ->assertSee('That date is too far in the future')
            ->assertSee('Please select a more reasonable date.');
    }

    public function testFailsIfTheToolIsNotRegistered(): void
    {
        $response = HotelT::tool(new class extends Tool {
            protected string $name = 'unknown/tool';

            /**
             * Return content for an unavailable tool.
             */
            public function handle(): string
            {
                return 'This should not be possible.';
            }
        });

        $response->assertHasErrors([
            'Tool [unknown/tool] not found.',
        ]);
    }

    public function testFailsIfTheToolIsNotRegisteredDueToTheShouldRegisterMethod(): void
    {
        $response = HotelT::tool(BookingTool::class, ['register' => false]);

        $response->assertHasErrors([
            'Tool [booking-tool] not found.',
        ]);
    }
}
