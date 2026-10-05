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
use Hypervel\Tests\Saloon\Fixtures\Requests\HasXmlBodyRequest;

class HasXmlBodyTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheDefaultBodyIsLoadedWithTheContentTypeHeader(): void
    {
        $request = new HasXmlBodyRequest;

        $this->assertSame('<p>Howdy</p>', $request->body());

        $pendingRequest = (new TestConnector)
            ->send($request, new MockClient([MockResponse::make()]))
            ->pendingRequest();

        $this->assertSame('application/xml', $pendingRequest->headers()['Content-Type']);
    }

    public function testTheGuzzleSenderProperlySendsIt(): void
    {
        $connector = new TestConnector;
        $request = new HasXmlBodyRequest;

        $request->middleware()->onRequest(function (PendingRequest $pendingRequest): void {
            $this->assertSame('application/xml', $pendingRequest->headers()['Content-Type']);
        });

        $asserted = false;

        Http::fake(function (HttpRequest $httpRequest) use (&$asserted): PromiseInterface {
            $this->assertSame(['application/xml'], $httpRequest->header('Content-Type'));
            $this->assertSame('<p>Howdy</p>', $httpRequest->body());

            $asserted = true;

            return Http::response();
        });

        $connector->send($request);

        $this->assertTrue($asserted);
    }
}
