<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Prompts\AssertNotificationTest;

use Generator;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Testing\TestResponse;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;

class RestaurantP extends Server
{
    protected array $prompts = [
        ReservationPrompt::class,
    ];
}

class ReservationPrompt extends Prompt
{
    /**
     * Stream booking notifications and the reservation result.
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('booking/starting', ['step' => 1]);

        $date = $request->date('date');

        if ($date?->isPast()) {
            yield Response::error('The booking date cannot be in the past.');
        }

        if ($date?->year === 2999) {
            yield [
                'You must be joking! That date is too far in the future.',
                'Please select a more reasonable date.',
            ];
        }

        yield Response::notification('booking/completed', ['step' => 2]);

        yield 'Your booking is confirmed!';
    }
}

class AssertNotificationTest extends TestCase
{
    public function testMayAssertThatTextIsSeenWhenReturningStringContent(): void
    {
        $response = RestaurantP::prompt(ReservationPrompt::class);

        $response->assertSee('Your booking is confirmed!');
    }

    public function testMayAssertTwoNotificationsGotSent(): void
    {
        $response = RestaurantP::prompt(ReservationPrompt::class);

        $response->assertNotificationCount(2)
            ->assertSentNotification('booking/starting', ['step' => 1])
            ->assertSentNotification('booking/completed', ['step' => 2]);
    }

    public function testMayFailToAssertTheNotificationCountIsWrong(): void
    {
        $response = RestaurantP::prompt(ReservationPrompt::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertNotificationCount(3);
    }

    public function testMayFailToAssertANotificationThatWasNotSent(): void
    {
        $response = RestaurantP::prompt(ReservationPrompt::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertSentNotification('booking/unknown');
    }

    public function testMayFailToAssertANotificationThatWasSentWithWrongParams(): void
    {
        $response = RestaurantP::prompt(ReservationPrompt::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertSentNotification('booking/starting', ['step' => 2]);
    }

    public function testRetainsNotificationsWhenTheStreamEndsWithAnError(): void
    {
        RestaurantP::prompt(ReservationPrompt::class, ['date' => '2000-01-01'])
            ->assertHasErrors(['The booking date cannot be in the past.'])
            ->assertNotificationCount(2)
            ->assertSentNotification('booking/starting', ['step' => 1])
            ->assertSentNotification('booking/completed', ['step' => 2]);
    }

    public function testAnErrorWithoutAnIdIsNotANotification(): void
    {
        $response = new TestResponse(new ReservationPrompt, JsonRpcResponse::error(null, -32603, 'Booking failed.'));

        $response->assertHasErrors(['Booking failed.'])->assertNotificationCount(0);
    }
}
