<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Body;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasStreamBodyRequest;

class HasStreamBodyTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheDefaultBodyIsLoaded(): void
    {
        $request = new HasStreamBodyRequest;

        $this->assertIsResource($request->body());

        // Stream bodies do not assume a content type.
        $pendingRequest = (new TestConnector)
            ->send($request, new MockClient([MockResponse::make()]))
            ->pendingRequest();

        $this->assertFalse($pendingRequest->hasHeader('Content-Type'));
    }

    public function testTheGuzzleSenderProperlySendsIt(): void
    {
        $connector = new TestConnector;
        $request = new HasStreamBodyRequest;

        $request->withHeader('Content-Type', 'application/custom');

        $asserted = false;

        Http::fake(function (HttpRequest $httpRequest) use (&$asserted): PromiseInterface {
            $this->assertSame(['application/custom'], $httpRequest->header('Content-Type'));
            $this->assertSame('Howdy, Partner', $httpRequest->body());

            $asserted = true;

            return Http::response();
        });

        $connector->send($request);

        $this->assertTrue($asserted);
    }

    public function testAPsrRequestSnapshotBeforeSendingKeepsAResourceBodyOpen(): void
    {
        $request = new HasStreamBodyRequest;
        $snapshotBody = null;

        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$snapshotBody): void {
            $snapshotBody = (string) $pendingRequest->toPsrRequest()->getBody();
        });

        $pendingRequest = (new TestConnector)
            ->send($request, new MockClient([MockResponse::make()]))
            ->pendingRequest();

        $this->assertSame('Howdy, Partner', $snapshotBody);
        $this->assertSame('Howdy, Partner', (string) $pendingRequest->preparedBody());
    }
}
