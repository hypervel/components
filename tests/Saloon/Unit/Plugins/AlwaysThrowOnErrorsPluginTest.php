<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Plugins;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\Request\ServerException;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\AlwaysThrowRequest;

class AlwaysThrowOnErrorsPluginTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    // Upstream calls its live error endpoint; a mocked 500 response proves the same behavior.
    public function testItAlwaysThrowsAnErrorIfThePluginHasBeenAdded(): void
    {
        $this->expectException(ServerException::class);

        (new TestConnector)->send(new AlwaysThrowRequest, new MockClient([
            MockResponse::make(['message' => 'Server Error'], 500),
        ]));
    }
}
