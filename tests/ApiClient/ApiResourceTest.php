<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use BadMethodCallException;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\ApiResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @covers \Hypervel\ApiClient\ApiResource
 */
class ApiResourceTest extends TestCase
{
    /**
     * @var MockObject&ApiResponse
     */
    private $response;

    /**
     * @var MockObject&ApiRequest
     */
    private $request;

    private ApiResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for the Response and Request
        $this->response = $this->createMock(ApiResponse::class);
        $this->request = $this->createMock(ApiRequest::class);

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
            ->method('body')
            ->willReturn($responseBody = '{"key": "value"}');

        $this->assertEquals($responseBody, (string) $this->resource);
    }

    public function testResolve(): void
    {
        $this->response
            ->method('json')
            ->willReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->resolve());
    }

    public function testToArray(): void
    {
        $this->response
            ->method('json')
            ->willReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->toArray());
    }

    public function testJsonSerialize(): void
    {
        $this->response
            ->method('json')
            ->willReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->jsonSerialize());
    }

    public function testArrayAccessOffsetExists(): void
    {
        $this->response->method('offsetExists')
            ->with('key')
            ->willReturn(true);

        $this->assertTrue($this->resource->offsetExists('key'));
    }

    public function testArrayAccessOffsetGet(): void
    {
        $this->response->method('offsetGet')
            ->with('key')
            ->willReturn($value = 'someValue');

        $this->assertEquals($value, $this->resource->offsetGet('key'));
    }

    public function testArrayAccessOffsetSet(): void
    {
        $this->response->expects($this->once())
            ->method('offsetSet')
            ->with($key = 'key', $value = 'value');

        $this->resource->offsetSet($key, $value);
    }

    public function testArrayAccessOffsetUnset(): void
    {
        $this->response->expects($this->once())
            ->method('offsetUnset')
            ->with($key = 'key');

        $this->resource->offsetUnset($key);
    }

    public function testMagicIssetMethod(): void
    {
        $this->response
            ->method('json')
            ->willReturn($jsonData = ['existingKey' => 'value']);

        $this->assertTrue(isset($this->resource->existingKey));
        $this->assertFalse(isset($this->resource->nonExistingKey));
    }

    public function testMagicUnsetMethod(): void
    {
        $this->response->expects($this->once())
            ->method('offsetUnset')
            ->with('key');

        unset($this->resource->key);
    }

    public function testMagicGetMethod(): void
    {
        $this->response->method('offsetGet')
            ->with('key')
            ->willReturn($value = 'value');

        /* @phpstan-ignore-next-line */
        $this->assertEquals($value, $this->resource->key);
    }

    public function testCallMethodOnResponse(): void
    {
        $this->response->method('status')
            ->willReturn($expectedResult = 200);

        $this->assertEquals($expectedResult, $this->resource->status());
    }

    public function testCallNonExistentMethodThrowsException(): void
    {
        $this->expectException(BadMethodCallException::class);

        /* @phpstan-ignore-next-line */
        $this->resource->nonExistentMethod();
    }
}
