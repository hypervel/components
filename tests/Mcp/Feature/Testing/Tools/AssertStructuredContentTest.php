<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Feature\Testing\Tools\AssertStructuredContentTest;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Testing\Fluent\AssertableJson;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\ExpectationFailedException;
use stdClass;

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
        ListBookingsTool::class,
        EmptyStructuredContentTool::class,
        GetBookingFiltersTool::class,
    ];
}

class ListBookingsTool extends Tool
{
    protected string $name = 'booking/list';

    protected string $title = 'List bookings';

    protected string $description = 'List bookings';

    /**
     * Return a structured list of bookings.
     */
    public function handle(): ResponseFactory
    {
        return Response::structured([
            'data' => [
                ['id' => 123, 'status' => BookingStatus::Confirmed],
                ['id' => 124, 'status' => BookingStatus::Confirmed],
            ],
            'meta' => [
                'total' => 2,
            ],
        ]);
    }
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

class EmptyStructuredContentTool extends Tool
{
    /**
     * Return an explicitly empty structured result.
     */
    public function handle(): ResponseFactory
    {
        return Response::make(Response::text('No booking'))->withStructuredContent([]);
    }
}

class GetBookingFiltersTool extends Tool
{
    /**
     * Return structured content with JSON objects that decode to PHP lists.
     */
    public function handle(): ResponseFactory
    {
        return Response::structured([
            'filters' => new stdClass,
            'labels' => (object) ['priority'],
        ]);
    }
}

class AssertStructuredContentTest extends TestCase
{
    public function testMayAssertEmptyStructuredContent(): void
    {
        BookingServer::tool(EmptyStructuredContentTool::class)
            ->assertStructuredContent([])
            ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->count(0));
    }

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

    public function testMayAssertTheStructuredContentCount(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $response->assertStructuredContentCount(3);
    }

    public function testMayAssertTheStructuredContentCountAtAKey(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentCount(2, 'data');
    }

    public function testFailsToAssertTheStructuredContentCountIsWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentCount(3, 'data');
    }

    public function testFailsToAssertTheStructuredContentCountWhenItIsNotPresent(): void
    {
        $response = BookingServer::tool(GetBookingWithoutStructuredContentTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentCount(0);
    }

    public function testMayAssertTheStructuredContentPath(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response
            ->assertStructuredContentPath('data.1.id', 124)
            ->assertStructuredContentPath('meta', ['total' => 2]);
    }

    public function testMayAssertTheStructuredContentPathWithClosure(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentPath('meta.total', fn (int $total): bool => $total === 2);
    }

    public function testFailsToAssertTheStructuredContentPathIsWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentPath('data.1.id', 123);
    }

    public function testFailsToAssertTheStructuredContentPathWithClosure(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentPath('meta.total', fn (int $total): bool => $total === 3);
    }

    public function testMayAssertTheStructuredContentPathWithAnEnum(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentPath('data.0.status', BookingStatus::Confirmed);
    }

    public function testFailsToAssertTheStructuredContentPathWhenItIsNotPresent(): void
    {
        $response = BookingServer::tool(GetBookingWithoutStructuredContentTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentPath('id', 123);
    }

    public function testMayAssertTheStructuredContentPaths(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentPaths([
            'data.0.id' => 123,
            'data.1.status' => BookingStatus::Confirmed,
            'meta.total' => 2,
        ]);
    }

    public function testFailsToAssertTheStructuredContentPathsAreWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentPaths([
            'data.0.id' => 123,
            'meta.total' => 3,
        ]);
    }

    public function testMayAssertTheStructuredContentPathCanonicalizing(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentPathCanonicalizing('data.*.id', [124, 123]);
    }

    public function testFailsToAssertTheStructuredContentPathCanonicalizingIsWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentPathCanonicalizing('data.*.id', [124, 125]);
    }

    public function testMayAssertTheExactStructuredContentRegardlessOfKeyOrder(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $response->assertExactStructuredContent([
            'status' => 'confirmed',
            'id' => 123,
            'type' => 'booking',
        ]);
    }

    public function testFailsToAssertTheExactStructuredContentIsWrong(): void
    {
        $response = BookingServer::tool(GetBookingTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertExactStructuredContent([
            'id' => 123,
            'status' => 'confirmed',
        ]);
    }

    public function testMayAssertAStructuredContentFragment(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentFragment(['id' => 124]);
    }

    public function testFailsToAssertAStructuredContentFragmentThatIsNotPresent(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentFragment(['id' => 125]);
    }

    public function testFailsToAssertAStructuredContentFragmentWhenItIsNotPresent(): void
    {
        $response = BookingServer::tool(GetBookingWithoutStructuredContentTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentFragment(['id' => 123]);
    }

    public function testMayAssertAStructuredContentPathIsMissing(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentMissingPath('data.2');
    }

    public function testFailsToAssertAStructuredContentPathIsMissingWhenItIsPresent(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentMissingPath('meta.total');
    }

    public function testMayAssertTheStructuredContentStructure(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentStructure([
            'data' => [
                '*' => ['id', 'status'],
            ],
            'meta' => ['total'],
        ]);
    }

    public function testFailsToAssertTheStructuredContentStructureIsWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentStructure([
            'meta' => ['per_page'],
        ]);
    }

    public function testMayAssertTheStructuredContentPathsCanonicalizing(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentPathsCanonicalizing([
            'data.*.id' => [124, 123],
            'data.*.status' => ['confirmed', 'confirmed'],
        ]);
    }

    public function testFailsToAssertTheStructuredContentPathsCanonicalizingAreWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentPathsCanonicalizing([
            'data.*.id' => [124, 123],
            'data.*.status' => ['confirmed', 'pending'],
        ]);
    }

    public function testMayAssertSimilarStructuredContentRegardlessOfOrder(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertSimilarStructuredContent([
            'meta' => [
                'total' => 2,
            ],
            'data' => [
                ['status' => 'confirmed', 'id' => 124],
                ['status' => 'confirmed', 'id' => 123],
            ],
        ]);
    }

    public function testFailsToAssertSimilarStructuredContentIsWrong(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertSimilarStructuredContent([
            'data' => [
                ['id' => 123, 'status' => 'confirmed'],
            ],
            'meta' => [
                'total' => 2,
            ],
        ]);
    }

    public function testMayAssertStructuredContentFragments(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentFragments([
            ['id' => 123],
            ['total' => 2],
        ]);
    }

    public function testFailsToAssertStructuredContentFragmentsThatAreNotPresent(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentFragments([
            ['id' => 123],
            ['total' => 3],
        ]);
    }

    public function testMayAssertStructuredContentIsMissing(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentMissing(['id' => 125]);
    }

    public function testFailsToAssertStructuredContentIsMissingWhenItIsPresent(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentMissing(['id' => 124]);
    }

    public function testMayAssertExactStructuredContentIsMissing(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentMissingExact(['id' => 124, 'status' => 'pending']);
    }

    public function testFailsToAssertExactStructuredContentIsMissingWhenItIsPresent(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(AssertionFailedError::class);
        $response->assertStructuredContentMissingExact(['id' => 124, 'status' => 'confirmed']);
    }

    public function testMayAssertStructuredContentPathsAreMissing(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentMissingPaths(['data.2', 'meta.per_page']);
    }

    public function testFailsToAssertStructuredContentPathsAreMissingWhenOneIsPresent(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentMissingPaths(['data.2', 'meta.total']);
    }

    public function testMayAssertTheExactStructuredContentStructure(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertExactStructuredContentStructure([
            'data' => [
                '*' => ['id', 'status'],
            ],
            'meta' => ['total'],
        ]);
    }

    public function testFailsToAssertTheExactStructuredContentStructureWhenKeysAreNotListed(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertExactStructuredContentStructure([
            'data' => [
                '*' => ['id'],
            ],
            'meta' => ['total'],
        ]);
    }

    public function testMayAssertTheStructuredContentIsAnArray(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response->assertStructuredContentIsArray('data');
    }

    public function testFailsToAssertTheStructuredContentIsAnArrayWhenItIsAnObject(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentIsArray('meta');
    }

    #[TestWith([EmptyStructuredContentTool::class, null])]
    #[TestWith([GetBookingFiltersTool::class, 'filters'])]
    #[TestWith([GetBookingFiltersTool::class, 'labels'])]
    public function testFailsToAssertTheStructuredContentIsAnArrayWhenAnObjectDecodesToAList(string $tool, ?string $key): void
    {
        $response = BookingServer::tool($tool);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentIsArray($key);
    }

    public function testMayAssertTheStructuredContentIsAnObject(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $response
            ->assertStructuredContentIsObject()
            ->assertStructuredContentIsObject('meta');
    }

    public function testMayAssertTheStructuredContentIsAnObjectWhenItDecodesToAList(): void
    {
        BookingServer::tool(EmptyStructuredContentTool::class)->assertStructuredContentIsObject();

        BookingServer::tool(GetBookingFiltersTool::class)
            ->assertStructuredContentIsObject('filters')
            ->assertStructuredContentIsObject('labels');
    }

    public function testFailsToAssertTheStructuredContentIsAnObjectWhenItIsAnArray(): void
    {
        $response = BookingServer::tool(ListBookingsTool::class);

        $this->expectException(ExpectationFailedException::class);
        $response->assertStructuredContentIsObject('data');
    }
}
