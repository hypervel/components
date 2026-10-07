<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\ModifiedPsrRequestConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\ModifiedPsrUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\NullHeaderRequest;

// Connectors are read-only, so the mock clients are passed to send() instead of being set on the connector.
class PsrTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheConnectorAndRequestCanModifyThePsrRequestWhenItIsCreated(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new ModifiedPsrRequestConnector;

        $response = $connector->send(new ModifiedPsrUserRequest, $mockClient);

        // The connector will change the URI to https://google.com

        $this->assertSame('https://google.com', (string) $response->toPsrRequest()->getUri());

        // The request will add the X-Howdy header

        $this->assertSame(['Yeehaw'], $response->toPsrRequest()->getHeader('X-Howdy'));
    }

    public function testThePsrRequestHeadersMustBeConvertedToEmptyString(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(headers: ['X-Null-Header' => null]),
        ]);

        $connector = new TestConnector;

        $response = $connector->send(new NullHeaderRequest, $mockClient);

        // The request will convert null to empty string
        $this->assertSame('', $response->toPsrRequest()->getHeader('X-Null-Header')[0]);

        // The response will convert null header to empty string
        $this->assertSame('', $response->header('X-Null-Header'));
        $this->assertSame([''], $response->headers()['X-Null-Header']);
    }
}
