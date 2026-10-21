<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\ResourceConnector;

class BaseResourceTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAResourceCanBeUsedToSendARequest(): void
    {
        // Connectors are read-only, so the mock client is registered globally instead of on the connector.
        Saloon::fixturePath(__DIR__ . '/../Fixtures/Saloon');
        Saloon::fake([
            MockResponse::fixture('user'),
        ]);

        $connector = new ResourceConnector;

        $this->assertSame([
            'name' => 'Sammyjo20',
            'actual_name' => 'Sam',
            'twitter' => '@carre_sam',
        ], $connector->user()->get());
    }
}
