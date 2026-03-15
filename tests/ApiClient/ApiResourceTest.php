<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use BadMethodCallException;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @covers \Hypervel\ApiClient\ApiResource
 */
class ApiResourceTest extends TestCase
{
    /**
     * @var ApiResponse&MockInterface
     */
    private $response;

    /**
     * @var ApiRequest&MockInterface
     */
    private $request;

    private ApiResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->response = m::mock(ApiResponse::class);
        $this->request = m::mock(ApiRequest::class);

        // Create the resource with our mocks
        $this->resource = new ApiResource($this->response, $this->request);
    }

    public function testMakeFactoryMethod(): void
    {
        $resource = ApiResource::make($this->response, $this->request);

        $this->assertInstanceOf(ApiResource::class, $resource);
        $this->assertSame($this->response, $resource->getResponse());
        $this->assertSame($this->request, $resource->getRequest());
    }

    public function testToString(): void
    {
        $this->response
            ->shouldReceive('body')
            ->andReturn($responseBody = '{"key": "value"}');

        $this->assertEquals($responseBody, (string) $this->resource);
    }

    public function testResolve(): void
    {
        $this->response
            ->shouldReceive('json')
            ->andReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->resolve());
    }

    public function testToArray(): void
    {
        $this->response
            ->shouldReceive('json')
            ->andReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->toArray());
    }

    public function testJsonSerialize(): void
    {
        $this->response
            ->shouldReceive('json')
            ->andReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->jsonSerialize());
    }

    public function testArrayAccessOffsetExists(): void
    {
        $this->response->shouldReceive('offsetExists')
            ->with('key')
            ->andReturn(true);

        $this->assertTrue($this->resource->offsetExists('key'));
    }

    public function testArrayAccessOffsetGet(): void
    {
        $this->response->shouldReceive('offsetGet')
            ->with('key')
            ->andReturn($value = 'someValue');

        $this->assertEquals($value, $this->resource->offsetGet('key'));
    }

    public function testArrayAccessOffsetSet(): void
    {
        $this->response->shouldReceive('offsetSet')
            ->once()
            ->with($key = 'key', $value = 'value');

        $this->resource->offsetSet($key, $value);
    }

    public function testArrayAccessOffsetUnset(): void
    {
        $this->response->shouldReceive('offsetUnset')
            ->once()
            ->with($key = 'key');

        $this->resource->offsetUnset($key);
    }

    public function testMagicIssetMethod(): void
    {
        $this->response
            ->shouldReceive('json')
            ->andReturn($jsonData = ['existingKey' => 'value']);

        $this->assertTrue(isset($this->resource->existingKey));
        $this->assertFalse(isset($this->resource->nonExistingKey));
    }

    public function testMagicUnsetMethod(): void
    {
        $this->response->shouldReceive('offsetUnset')
            ->once()
            ->with('key');

        unset($this->resource->key);
    }

    public function testMagicGetMethod(): void
    {
        $this->response->shouldReceive('offsetGet')
            ->with('key')
            ->andReturn($value = 'value');

        /* @phpstan-ignore-next-line */
        $this->assertEquals($value, $this->resource->key);
    }

    public function testCallMethodOnResponse(): void
    {
        $this->response->shouldReceive('status')
            ->andReturn($expectedResult = 200);

        $this->assertEquals($expectedResult, $this->resource->status());
    }

    public function testCallNonExistentMethodThrowsException(): void
    {
        $this->expectException(BadMethodCallException::class);

        /* @phpstan-ignore-next-line */
        $this->resource->nonExistentMethod();
    }
}
