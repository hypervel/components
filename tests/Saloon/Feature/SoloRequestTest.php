<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connectors\NullConnector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\SoloRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;

// Upstream's cases send real requests and are in tests/Integration/Saloon/Feature/SoloRequestTest.php.
class SoloRequestTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testItSendsAnAbsoluteEndpointThroughTheNormalLifecycle(): void
    {
        $request = new SoloRequestStub;
        $mockClient = new MockClient([new MockResponse('complete', 201)]);

        $response = $request->send($mockClient);

        $this->assertInstanceOf(NullConnector::class, $request->connector());
        $this->assertSame($request->connector(), $request->connector());
        $this->assertSame('https://api.example.com/users', (string) $response->pendingRequest()->uri());
        $this->assertSame(201, $response->status());
        $this->assertSame('complete', $response->body());
    }

    public function testItCanBePreparedThroughItsConnector(): void
    {
        $request = new SoloRequestStub;
        $pendingRequest = $request->createPendingRequest();

        $this->assertSame($request, $pendingRequest->request());
        $this->assertSame($request->connector(), $pendingRequest->connector());
        $this->assertSame('https://api.example.com/users', (string) $pendingRequest->uri());
    }
}

class SoloRequestStub extends SoloRequest
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return 'https://api.example.com/users';
    }
}
