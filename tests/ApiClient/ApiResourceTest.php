<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use Error;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JsonException;
use LogicException;

class ApiResourceTest extends TestCase
{
    use VerifiesDoubles;

    private ApiResponse&DoubleInterface $response;

    private ApiRequest&DoubleInterface $request;

    private ApiResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->response = Double::for(ApiResponse::class)->strict();
        $this->request = Double::for(ApiRequest::class)->strict();

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
            ->expects('body')
            ->returns($responseBody = '{"key": "value"}');

        $this->assertEquals($responseBody, (string) $this->resource);
    }

    public function testResolve(): void
    {
        $this->response
            ->expects('toArray')
            ->returns($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->resolve());
    }

    public function testToArray(): void
    {
        $this->response
            ->expects('toArray')
            ->returns($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->toArray());
    }

    public function testJsonSerialize(): void
    {
        $this->response
            ->expects('toArray')
            ->returns($jsonData = ['key' => 'value']);

        $this->assertEquals($jsonData, $this->resource->jsonSerialize());
    }

    public function testToJsonThrowsForInvalidUtf8(): void
    {
        $this->response->expects('toArray')->returns(['value' => "\xB1\x31"]);

        $this->expectException(JsonException::class);

        $this->resource->toJson();
    }

    public function testToJsonHonorsInvalidUtf8Substitution(): void
    {
        $this->response->expects('toArray')->returns(['value' => "\xB1\x31"]);

        $this->assertSame(
            '{"value":"\ufffd1"}',
            $this->resource->toJson(JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    public function testToPrettyJsonPreservesCallerOptions(): void
    {
        $this->response->expects('toArray')->returns(['value' => '/path']);

        $this->assertSame(
            "{\n    \"value\": \"/path\"\n}",
            $this->resource->toPrettyJson(JSON_UNESCAPED_SLASHES),
        );
    }

    public function testArrayAccessOffsetExists(): void
    {
        $this->response->expects('offsetExists')
            ->with('key')
            ->returns(true);

        $this->assertTrue($this->resource->offsetExists('key'));
    }

    public function testArrayAccessOffsetGet(): void
    {
        $this->response->expects('offsetGet')
            ->with('key')
            ->returns($value = 'someValue');

        $this->assertEquals($value, $this->resource->offsetGet('key'));
    }

    public function testArrayAccessOffsetSetIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Resource data cannot be assigned through array offsets.');

        $this->resource->offsetSet('key', 'value');
    }

    public function testArrayAccessOffsetUnsetIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Resource data cannot be unset through array offsets.');

        $this->resource->offsetUnset('key');
    }

    public function testMagicIssetMethod(): void
    {
        $this->response
            ->expects('json')->times(2)
            ->returns($jsonData = ['existingKey' => 'value']);

        $this->assertTrue(isset($this->resource->existingKey));
        $this->assertFalse(isset($this->resource->nonExistingKey));
    }

    public function testMagicPropertyAssignmentIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Resource data cannot be assigned through properties.');

        $this->resource->key = 'value';
    }

    public function testMagicPropertyUnsetIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('Resource data cannot be unset through properties.');

        unset($this->resource->key);
    }

    public function testMagicGetMethod(): void
    {
        $this->response->expects('offsetGet')
            ->with('key')
            ->returns($value = 'value');

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
        $this->response->expects('status')
            ->returns($expectedResult = 200);

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
        $resource = new ApiResource(new ApiResponse(new Psr7Response), $this->request);

        $this->expectException(Error::class);
        $this->expectExceptionMessageIs('Call to undefined method GuzzleHttp\Psr7\Response::nonExistentMethod()');

        $resource->nonExistentMethod();
    }
}
