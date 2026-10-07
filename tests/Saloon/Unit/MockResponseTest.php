<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Http\Faking\FakeResponse;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Repositories\Body\JsonBodyRepository;
use Hypervel\Saloon\Repositories\Body\StringBodyRepository;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequestWithCustomResponse;
use Hypervel\Tests\Saloon\Fixtures\Responses\UserData;
use Hypervel\Tests\Saloon\Fixtures\Responses\UserResponse;
use Mockery as m;
use RuntimeException;

class MockResponseTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testPullingAResponseFromTheSequenceWillReturnTheCorrectResponse(): void
    {
        $responseA = MockResponse::make();
        $responseB = MockResponse::make([], 500);
        $responseC = MockResponse::make([], 500);

        $mockClient = new MockClient([$responseA, $responseB, $responseC]);
        $pendingRequest = (new TestConnector)->createPendingRequest(new UserRequest);

        $this->assertSame($responseA->status(), $mockClient->match($pendingRequest)->status());
        $this->assertSame($responseB->status(), $mockClient->match($pendingRequest)->status());
        $this->assertSame($responseC->status(), $mockClient->match($pendingRequest)->status());
        $this->assertTrue($mockClient->isEmpty());
    }

    public function testAMockResponseCanHaveRawBodyData(): void
    {
        $response = MockResponse::make('xml', 200, ['Content-Type' => 'application/json']);

        $this->assertSame(['Content-Type' => 'application/json'], $response->headers());
        $this->assertSame(200, $response->status());
        $this->assertInstanceOf(StringBodyRepository::class, $response->body());
        $this->assertSame('xml', $response->body()->all());
    }

    public function testAResponseCanBeACustomResponseClass(): void
    {
        $mockClient = new MockClient([MockResponse::make(['foo' => 'bar'])]);
        $request = new UserRequestWithCustomResponse;

        $response = (new TestConnector)->send($request, $mockClient);

        $this->assertInstanceOf(UserResponse::class, $response);
        $this->assertInstanceOf(UserData::class, $response->customCastMethod());
        $this->assertSame('bar', $response->foo());
    }

    public function testItCreatesJsonAndStringResponses(): void
    {
        $json = new FakeResponse(['name' => 'Taylor'], 201, ['X-Count' => 2]);
        $text = new FakeResponse('complete');

        $this->assertInstanceOf(JsonBodyRepository::class, $json->body());
        $this->assertSame('{"name":"Taylor"}', (string) $json->createPsrResponse()->getBody());
        $this->assertSame(201, $json->status());
        $this->assertSame(['2'], $json->createPsrResponse()->getHeader('X-Count'));
        $this->assertInstanceOf(StringBodyRepository::class, $text->body());
        $this->assertSame('complete', (string) $text->createPsrResponse()->getBody());
    }

    public function testItResolvesConfiguredExceptionsAgainstThePendingRequest(): void
    {
        $pendingRequest = m::mock(PendingRequest::class);
        $exception = new RuntimeException('Failed.');
        $response = (new FakeResponse)->throw(
            fn (PendingRequest $request): RuntimeException => $request === $pendingRequest
                ? $exception
                : new RuntimeException('Wrong request.'),
        );

        $this->assertSame($exception, $response->getException($pendingRequest));
        $this->assertNull((new FakeResponse)->getException($pendingRequest));
    }
}
