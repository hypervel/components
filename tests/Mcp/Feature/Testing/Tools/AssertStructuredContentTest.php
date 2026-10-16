<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertStructuredContentTest;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Testing\Fluent\AssertableJson;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
}

class BookingServer extends Server
{
    protected array $tools = [
        GetBookingTool::class,
        GetBookingWithoutStructuredContentTool::class,
        GetBookingWithSerializableValuesTool::class,
    ];
}

class GetBookingWithSerializableValuesTool extends Tool
{
    protected string $name = 'booking/get/serializable';

    protected string $title = 'Get booking';

    protected string $description = 'Get a booking';

    /**
     * Return structured content with serializable values.
     */
    public function handle(): ResponseFactory
    {
        return Response::structured([
            'status' => BookingStatus::Confirmed,
            'amount' => 10.0,
        ]);
    }
}

class GetBookingTool extends Tool
{
    protected string $name = 'booking/get';

    protected string $title = 'Get booking';

    protected string $description = 'Get a booking';

    /**
     * Return the structured booking content.
     */
    public function handle(): ResponseFactory
    {
        return Response::structured([
            'type' => 'booking',
            'id' => 123,
            'status' => 'confirmed',
        ]);
    }
}

class GetBookingWithoutStructuredContentTool extends Tool
{
    protected string $name = 'booking/get/no-structured';

    protected string $title = 'Get booking';

    protected string $description = 'Get a booking';

    /**
     * Return plain text booking content.
     */
    public function handle(): Response
    {
        return Response::text('This is the confirmed booking 123');
    }
}

class AssertStructuredContentTest extends TestCase
{
    public function testMayAssertTheStructuredContent(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $response->assertStructuredContent([
            'type' => 'booking',
            'id' => 123,
            'status' => 'confirmed',
        ]);
    }

    public function testFailsToAssertTheStructuredContentIsWrong(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContent([
            'type' => 'booking',
            'id' => 124,
            'status' => 'pending',
        ]);
    }

    public function testFailsToAssertTheStructuredContentIsNotPresent(): void
    {
        $response = BookingServer::tool(GetBookingWithoutStructuredContentTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContent([
            'type' => 'booking',
            'id' => 123,
            'status' => 'confirmed',
        ]);
    }

    public function testSucceedsToAssertTheStructuredContentWithClosure(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $response->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->where('type', 'booking')->etc());
    }

    public function testFailsToAssertTheStructuredContentWithClosure(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->where('type', 'foo')->etc());
    }

    public function testSerializesTheStructuredContentBeforeAssertingWithAClosure(): void
    {
        $response = BookingServer::tool(GetBookingWithSerializableValuesTool::class);

        $response->assertStructuredContent(
            fn (AssertableJson $json): AssertableJson => $json
                ->where('status', BookingStatus::Confirmed)
                ->where('amount', 10)
                ->etc()
        );
    }

    public function testSerializesTheStructuredContentBeforeAssertingWithAnArray(): void
    {
        $response = BookingServer::tool(GetBookingWithSerializableValuesTool::class);

        $response->assertStructuredContent([
            'status' => BookingStatus::Confirmed,
            'amount' => 10.0,
        ]);
    }

    public function testFailsToAssertTheStructuredContentWithAClosureWhenItIsNotPresent(): void
    {
        $response = BookingServer::tool(GetBookingWithoutStructuredContentTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->etc());
    }
}
