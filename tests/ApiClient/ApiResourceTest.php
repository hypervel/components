<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use BadMethodCallException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\Tests\TestCase;
use JsonException;
use LogicException;
use Mockery as m;
use Mockery\MockInterface;

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
            ->shouldReceive('toArray')
            ->andReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->resolve());
    }

    public function testToArray(): void
    {
        $this->response
            ->shouldReceive('toArray')
            ->andReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->toArray());
    }

    public function testJsonSerialize(): void
    {
        $this->response
            ->shouldReceive('toArray')
            ->andReturn($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->jsonSerialize());
    }

    public function testToJsonThrowsForInvalidUtf8(): void
    {
        $this->response->shouldReceive('toArray')->once()->andReturn(['value' => "\xB1\x31"]);

        $this->expectException(JsonException::class);

        $this->resource->toJson();
    }

    public function testToJsonHonorsInvalidUtf8Substitution(): void
    {
        $this->response->shouldReceive('toArray')->once()->andReturn(['value' => "\xB1\x31"]);

        $this->assertSame(
            '{"value":"\ufffd1"}',
            $this->resource->toJson(JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    public function testToPrettyJsonPreservesCallerOptions(): void
    {
        $this->response->shouldReceive('toArray')->once()->andReturn(['value' => '/path']);

        $this->assertSame(
            "{\n    \"value\": \"/path\"\n}",
            $this->resource->toPrettyJson(JSON_UNESCAPED_SLASHES),
        );
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

    public function testArrayAccessOffsetSetIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource data cannot be assigned through array offsets.');

        $this->resource->offsetSet('key', 'value');
    }

    public function testArrayAccessOffsetUnsetIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource data cannot be unset through array offsets.');

        $this->resource->offsetUnset('key');
    }

    public function testMagicIssetMethod(): void
    {
        $this->response
            ->shouldReceive('json')
            ->andReturn($jsonData = ['existingKey' => 'value']);

        $this->assertTrue(isset($this->resource->existingKey));
        $this->assertFalse(isset($this->resource->nonExistingKey));
    }

    public function testMagicPropertyAssignmentIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource data cannot be assigned through properties.');

        $this->resource->key = 'value';
    }

    public function testMagicPropertyUnsetIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource data cannot be unset through properties.');

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

    public function testMissingArrayAndPropertyValuesReturnNull(): void
    {
        $resource = new ApiResource(
            new ApiResponse(new Psr7Response(200, body: '{"name":"Taylor"}')),
            $this->request,
        );

        $this->assertNull($resource['email']);
        $this->assertNull($resource->email);
    }

    public function testCallMethodOnResponse(): void
    {
        $this->response->shouldReceive('status')
            ->andReturn($expectedResult = 200);

        $this->assertEquals($expectedResult, $this->resource->status());
    }

    public function testCallForwardsResponseMacrosAndPsrMethods(): void
    {
        ApiResponse::macro('greeting', fn (): string => 'hello');
        $resource = new ApiResource(
            new ApiResponse(new Psr7Response(201)),
            $this->request,
        );

        $this->assertSame('hello', $resource->greeting());
        $this->assertSame(201, $resource->getStatusCode());
    }

    public function testCallNonExistentMethodThrowsException(): void
    {
        $this->expectException(BadMethodCallException::class);

        /* @phpstan-ignore-next-line */
        $this->resource->nonExistentMethod();
    }
}
