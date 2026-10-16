<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Resources\AssertSeeTest;

use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class HotelR extends Server
{
    protected array $resources = [
        BookingResource::class,
    ];
}

class BookingResource extends Resource
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
     * Determine whether this resource is available for the request.
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
        $response = HotelR::resource(BookingResource::class);

        $response->assertSee('Your booking is confirmed!')
            ->assertDontSee('The booking date cannot be in the past.')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testMayAssertThatTextIsSeenWhenProvidingArguments(): void
    {
        $response = HotelR::resource(BookingResource::class, ['date' => now()->addDay()->toDateString()]);

        $response->assertSee('Your booking is confirmed!')
            ->assertDontSee('The booking date cannot be in the past.')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testMayAssertThatTextIsSeenWhenProvidingArgumentsThatAreWrong(): void
    {
        $response = HotelR::resource(BookingResource::class, ['date' => now()->subDay()->toDateString()]);

        $response
            ->assertSee('The booking date cannot be in the past.')
            ->assertDontSee('Your booking is confirmed!')
            ->assertDontSee('Please select a more reasonable date.');
    }

    public function testFailsToAssertThatTextIsSeenWhenNotPresent(): void
    {
        $response = HotelR::resource(BookingResource::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertSee('This text is not present');
    }

    public function testFailsToAssertThatTextIsNotSeenWhenItIsPresent(): void
    {
        $response = HotelR::resource(BookingResource::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertDontSee('Your booking is confirmed!');
    }

    public function testMayAssertThatTextIsSeenWhenReturningArrayContent(): void
    {
        $response = HotelR::resource(BookingResource::class, ['date' => '2999-01-01']);

        $response
            ->assertSee('That date is too far in the future')
            ->assertSee('Please select a more reasonable date.');
    }

    public function testFailsIfTheResourceIsNotRegistered(): void
    {
        $response = HotelR::resource(new class extends Resource {
            protected string $uri = 'file://resources/hotel.md';

            /**
             * Return content for an unavailable resource.
             */
            public function handle(): string
            {
                return 'This should not be possible.';
            }
        });

        $response->assertHasErrors([
            'Resource [file://resources/hotel.md] not found.',
        ]);
    }

    public function testFailsIfTheResourceIsNotRegisteredDueToTheShouldRegisterMethod(): void
    {
        $response = HotelR::resource(BookingResource::class, ['register' => false]);

        $response->assertHasErrors([
            'Resource [file://resources/booking-resource] not found.',
        ]);
    }
}
