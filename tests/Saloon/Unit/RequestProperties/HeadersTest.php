<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\RequestProperties;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Connectors\HeaderConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HeaderRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\TestCase;
use Mockery as m;

class HeadersTest extends TestCase
{
    public function testDefaultHeadersAreMergedInFromARequest(): void
    {
        $request = new HeaderRequest;

        $this->assertSame(['X-Custom-Header' => 'Howdy'], $request->headers());
    }

    public function testHeadersCanBeManagedOnARequest(): void
    {
        $request = new HeaderRequest;

        $this->assertSame($request, $request->withHeader('Content-Type', 'custom/saloon'));

        // Upstream's merge() replaces existing values; withHeaders() appends, so replaceHeaders() is the equivalent.
        $this->assertSame($request, $request->replaceHeaders([
            'X-Merge-A' => 'Hello',
            'Complex' => ['A', 'B'],
            'X-Merge-B' => 'Goodbye',
            'Content-Type' => 'overwritten',
        ]));
        $this->assertSame($request, $request->withoutHeader('X-Merge-B'));

        $this->assertEquals([
            'X-Custom-Header' => 'Howdy',
            'Content-Type' => 'overwritten',
            'X-Merge-A' => 'Hello',
            'Complex' => ['A', 'B'],
        ], $request->headers());
        $this->assertSame('Howdy', $request->headers()['X-Custom-Header']);
        $this->assertSame(['A', 'B'], $request->headers()['Complex']);

        // Upstream's set() replaces every header; there is no replace-all method, so remove the headers before adding.
        $request->withoutHeaders(array_keys($request->headers()))->withHeader('X-Different', 'Yo');

        $this->assertSame(['X-Different' => 'Yo'], $request->headers());
        $this->assertNotEmpty($request->headers());
    }

    public function testHeadersCanBeManagedOnAConnector(): void
    {
        // Connectors are read-only and may be shared between coroutines, so their default headers are managed on
        // the pending request that merges them.
        $connector = new HeaderConnector;
        $pendingRequest = $this->pendingRequest($connector, new UserRequest);

        $pendingRequest->withHeader('Content-Type', 'custom/saloon');
        $pendingRequest->replaceHeaders([
            'X-Merge-A' => 'Hello',
            'Complex' => ['A', 'B'],
            'X-Merge-B' => 'Goodbye',
            'Content-Type' => 'overwritten',
        ]);
        $pendingRequest->withoutHeader('X-Merge-B');

        $this->assertEquals([
            'X-Connector-Header' => 'Sam',
            'Content-Type' => 'overwritten',
            'X-Merge-A' => 'Hello',
            'Complex' => ['A', 'B'],
        ], $pendingRequest->headers());
        $this->assertSame('Sam', $pendingRequest->headers()['X-Connector-Header']);
        $this->assertSame(['A', 'B'], $pendingRequest->headers()['Complex']);

        $pendingRequest->withoutHeaders(array_keys($pendingRequest->headers()))->withHeader('X-Different', 'Yo');

        $this->assertSame(['X-Different' => 'Yo'], $pendingRequest->headers());
        $this->assertSame(['X-Connector-Header' => 'Sam'], $connector->headers());
    }

    public function testRemovingAHeaderFromARequestDoesNotRemoveConnectorDefaults(): void
    {
        $request = (new HeaderRequest)->withoutHeader('X-Connector-Header');
        $pendingRequest = $this->pendingRequest(new HeaderConnector, $request);

        $this->assertSame('Sam', $pendingRequest->headers()['X-Connector-Header']);

        $pendingRequest->withoutHeader('x-connector-header');

        $this->assertSame(['X-Custom-Header' => 'Howdy'], $pendingRequest->headers());
    }

    public function testAddedHeadersAppendWhileReplacementsAndRemovalsIgnoreCase(): void
    {
        $request = (new HeaderRequest)->withHeader('X-Custom-Header', 'Partner');

        $this->assertSame(['X-Custom-Header' => ['Howdy', 'Partner']], $request->headers());

        $request->replaceHeaders(['x-custom-header' => 'Yeehaw']);

        $this->assertSame(['x-custom-header' => 'Yeehaw'], $request->headers());

        $request->withoutHeaders(['X-CUSTOM-HEADER']);

        $this->assertSame([], $request->headers());
        $this->assertFalse($request->hasHeader('X-Custom-Header'));
    }

    public function testReplaceHeadersMatchesNamesCaseInsensitively(): void
    {
        $request = (new UserRequest)
            ->withHeaders([
                'Authorization' => 'Bearer old',
                'X-Keep' => 'yes',
            ])
            ->replaceHeaders([
                'authorization' => 'Bearer new',
                'AUTHORIZATION' => 'Bearer newest',
            ]);

        $this->assertSame([
            'X-Keep' => 'yes',
            'AUTHORIZATION' => 'Bearer newest',
        ], $request->headers());
    }

    public function testIncomingHeaderWinsWhenAnExistingCaseVariantFollowsItsExactName(): void
    {
        $request = (new UserRequest)
            ->withHeaders([
                'Authorization' => 'Bearer stale exact',
                'authorization' => 'Bearer stale variant',
                'X-Keep' => 'yes',
            ])
            ->replaceHeaders([
                'Authorization' => 'Bearer new',
            ]);

        $this->assertSame([
            'X-Keep' => 'yes',
            'Authorization' => 'Bearer new',
        ], $request->headers());
    }

    public function testAcceptReplacesTheExistingAcceptHeader(): void
    {
        $request = (new UserRequest)
            ->accept('text/plain')
            ->acceptJson();

        $this->assertSame('application/json', $request->headers()['Accept']);
    }

    /**
     * Create a pending request with isolated framework dependencies.
     */
    protected function pendingRequest(Connector $connector, Request $request): PendingRequest
    {
        return new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}
