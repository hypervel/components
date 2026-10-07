<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Body;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\HasStringBodyRequest;

class HasStringBodyTest extends TestCase
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
        $request = new HasStringBodyRequest;

        $this->assertSame('name: Sam', $request->body());

        // String bodies do not assume a content type.
        $pendingRequest = (new TestConnector)
            ->send($request, new MockClient([MockResponse::make()]))
            ->pendingRequest();

        $this->assertFalse($pendingRequest->hasHeader('Content-Type'));
    }

    public function testTheGuzzleSenderProperlySendsIt(): void
    {
        $connector = new TestConnector;
        $request = new HasStringBodyRequest;

        $request->withHeader('Content-Type', 'application/custom');

        $asserted = false;

        Http::fake(function (HttpRequest $httpRequest) use (&$asserted): PromiseInterface {
            $this->assertSame(['application/custom'], $httpRequest->header('Content-Type'));
            $this->assertSame('name: Sam', $httpRequest->body());

            $asserted = true;

            return Http::response();
        });

        $connector->send($request);

        $this->assertTrue($asserted);
    }
}
