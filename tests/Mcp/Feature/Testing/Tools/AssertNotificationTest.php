<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertNotificationTest;

use Generator;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;

enum ReservationStep: string
{
    case Starting = 'starting';
}

class RestaurantT extends Server
{
    protected array $tools = [
        ReservationTool::class,
        SerializableNotificationTool::class,
        ReservationProgressTool::class,
    ];
}

class ReservationProgressTool extends Tool
{
    /**
     * Stream progress notifications and the booking confirmation.
     */
    public function handle(): Generator
    {
        yield Response::progress(1, total: 2, message: 'Checking tables');

        yield Response::progress(2);

        yield 'Your booking is confirmed!';
    }
}

class SerializableNotificationTool extends Tool
{
    /**
     * Stream notifications containing serializable parameters.
     */
    public function handle(): Generator
    {
        yield Response::notification('booking/step', ['step' => ReservationStep::Starting]);

        yield Response::notification('booking/empty', []);

        yield 'Your booking is confirmed!';
    }
}

class ReservationTool extends Tool
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
        $response = RestaurantT::tool(ReservationTool::class);

        $response->assertSee('Your booking is confirmed!');
    }

    public function testMayAssertTwoNotificationsGotSent(): void
    {
        $response = RestaurantT::tool(ReservationTool::class);

        $response->assertNotificationCount(2)
            ->assertSentNotification('booking/starting', ['step' => 1])
            ->assertSentNotification('booking/completed', ['step' => 2]);
    }

    public function testMayFailToAssertTheNotificationCountIsWrong(): void
    {
        $response = RestaurantT::tool(ReservationTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertNotificationCount(3);
    }

    public function testMayFailToAssertANotificationThatWasNotSent(): void
    {
        $response = RestaurantT::tool(ReservationTool::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertSentNotification('booking/unknown');
    }

    public function testMayFailToAssertANotificationThatWasSentWithWrongParams(): void
    {
        $response = RestaurantT::tool(ReservationTool::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertSentNotification('booking/starting', ['step' => 2]);
    }

    public function testSerializesNotificationParamsBeforeAsserting(): void
    {
        $response = RestaurantT::tool(SerializableNotificationTool::class);

        $response->assertSentNotification('booking/step', ['step' => ReservationStep::Starting])
            ->assertSentNotification('booking/step', ['step' => 'starting']);
    }

    public function testMayAssertANotificationThatWasSentWithEmptyParams(): void
    {
        $response = RestaurantT::tool(SerializableNotificationTool::class);

        $response->assertSentNotification('booking/empty', []);
    }

    public function testMayAssertProgressNotificationsGotSent(): void
    {
        $response = RestaurantT::tool(ReservationProgressTool::class);

        $response->assertNotificationCount(2)
            ->assertSentProgress(1, 2, 'Checking tables')
            ->assertSentProgress(2);
    }

    /**
     * @param array<int, null|float|int|string> $expected
     */
    #[DataProvider('unexpectedProgress')]
    public function testMayFailToAssertProgressThatWasNotSent(array $expected): void
    {
        $response = RestaurantT::tool(ReservationProgressTool::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertSentProgress(...$expected);
    }

    /**
     * Provide mismatched progress expectations.
     *
     * @return array<string, array{array<int, null|float|int|string>}>
     */
    public static function unexpectedProgress(): array
    {
        return [
            'wrong progress' => [[3]],
            'missing total' => [[1]],
            'wrong message' => [[1, 2, 'Seating guests']],
        ];
    }
}
