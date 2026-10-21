<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Hypervel\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\FakeResponse;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class SimulatedResponsePayloadTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testIfASimulatedResponsePayloadWasProvidedBeforeMockResponseItWillTakePriority(): void
    {
        Saloon::fake([
            new MockResponse(['name' => 'Sam'], 200, ['X-Greeting' => 'Howdy']),
        ]);

        $fakeResponse = new FakeResponse(['name' => 'Gareth'], 201, ['X-Greeting' => 'Hello']);

        $request = new UserRequest;
        $request->middleware()->onRequest(fn (): FakeResponse => $fakeResponse);

        $response = TestConnector::make()->send($request);

        $this->assertSame(['name' => 'Gareth'], $response->json());
        $this->assertSame(201, $response->status());
        $this->assertSame('Hello', $response->header('X-Greeting'));
    }
}
