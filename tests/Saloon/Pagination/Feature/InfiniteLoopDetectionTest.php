<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Saloon\Exceptions\NoMockResponseFoundException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Pagination\Exceptions\PaginationException;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\DisabledInfiniteLoopConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;

// Retrying a page does not count as another page, so the message names pages instead of upstream's requests.
class InfiniteLoopDetectionTest extends PaginationTestCase
{
    public function testThePaginationPluginCanDetectAPotentialInfiniteLoop(): void
    {
        $mockClient = new MockClient([
            SuperheroPagedRequest::class => MockResponse::make(['next_page_url' => 'infinity']),
        ]);

        Saloon::fake($mockClient);

        $connector = new PagedConnector;

        // We'll create a paginator that expects the "next_page_url" to be empty.
        // This will compare the responses and throw an exception if the last
        // five responses have been the same.

        $paginator = $connector->paginate(new SuperheroPagedRequest);

        $thrownException = false;

        try {
            iterator_to_array($paginator);
        } catch (PaginationException $exception) {
            $this->assertSame('Potential infinite loop detected! The last 5 pages have had exactly the same body. You can use the $detectInfiniteLoop property on your paginator to disable this check.', $exception->getMessage());

            $mockClient->assertSentCount(5);

            $thrownException = true;
        }

        $this->assertTrue($thrownException);
    }

    public function testThePaginationPluginCanDetectAPotentialInfiniteLoopAfterTheInitialRequest(): void
    {
        // We'll have two regular requests sent, and then we'll have 6 exactly the same
        // response bodies.

        $mockClient = new MockClient([
            MockResponse::make(['next_page_url' => '2']),
            MockResponse::make(['next_page_url' => '3']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
        ]);

        Saloon::fake($mockClient);

        $connector = new PagedConnector;

        $paginator = $connector->paginate(new SuperheroPagedRequest);

        $thrownException = false;

        try {
            iterator_to_array($paginator);
        } catch (PaginationException $exception) {
            $this->assertSame('Potential infinite loop detected! The last 5 pages have had exactly the same body. You can use the $detectInfiniteLoop property on your paginator to disable this check.', $exception->getMessage());

            $mockClient->assertSentCount(7);

            $thrownException = true;
        }

        $this->assertTrue($thrownException);
    }

    // Checksums use xxh128 instead of md5 and are keyed by page position, so a repeated load replaces its page's entry.
    public function testThePaginatorOnlyKeepsFiveResponseChecksumsInMemoryAtOnce(): void
    {
        $mockClient = new MockClient([
            MockResponse::make($responseA = ['next_page_url' => '1']),
            MockResponse::make($responseB = ['next_page_url' => '2']),
            MockResponse::make($responseC = ['next_page_url' => '3']),
            MockResponse::make($responseD = ['next_page_url' => '4']),
            MockResponse::make($responseE = ['next_page_url' => '5']),
            MockResponse::make($responseF = ['next_page_url' => '6']),
            MockResponse::make($responseG = ['next_page_url' => null]),
        ]);

        Saloon::fake($mockClient);

        $connector = new PagedConnector;

        $paginator = $connector->paginate(new SuperheroPagedRequest);

        iterator_to_array($paginator);

        $previousBodyChecksums = (fn (): array => $this->lastFiveBodyChecksums)->call($paginator);

        // We should only have four items because after each successful fifth attempt
        // we will remove the oldest one from the array.

        $this->assertCount(4, $previousBodyChecksums);

        $this->assertSame([
            3 => hash('xxh128', json_encode($responseD)),
            4 => hash('xxh128', json_encode($responseE)),
            5 => hash('xxh128', json_encode($responseF)),
            6 => hash('xxh128', json_encode($responseG)),
        ], $previousBodyChecksums);
    }

    public function testYouCanDisableTheInfiniteLoopDetectionOnAPaginator(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
            MockResponse::make(['next_page_url' => 'infinity']),
        ]);

        Saloon::fake($mockClient);

        $connector = new DisabledInfiniteLoopConnector;

        $paginator = $connector->paginate(new SuperheroPagedRequest);

        $thrownException = false;

        try {
            iterator_to_array($paginator);
        } catch (NoMockResponseFoundException) {
            // We'll detect a "NoMockResponseFoundException" because our mock client should attempt to
            // keep making requests because the infinite loop detection has been disabled.

            $thrownException = true;
        }

        $this->assertTrue($thrownException);
    }
}
