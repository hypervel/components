<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\SubRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequestWithBootPlugin;

class PluginTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAPluginBootMethodHasAccessToTheRequest(): void
    {
        $request = new UserRequestWithBootPlugin(1, 2);

        $pendingRequest = (new TestConnector)->createPendingRequest($request);
        $headers = $pendingRequest->headers();

        $this->assertSame(1, $headers['X-Plugin-User-Id']);
        $this->assertSame(2, $headers['X-Plugin-Group-Id']);
    }

    public function testSubRequestDoesNotNeedToUsePlugins(): void
    {
        $request = new SubRequest(1, 2);

        $pendingRequest = (new TestConnector)->createPendingRequest($request);
        $headers = $pendingRequest->headers();

        $this->assertSame(1, $headers['X-Plugin-User-Id']);
        $this->assertSame(2, $headers['X-Plugin-Group-Id']);
    }
}
