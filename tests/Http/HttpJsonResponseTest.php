<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Http\JsonResponse;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class HttpJsonResponseTest extends TestCase
{
    #[DataProvider('setAndRetrieveDataProvider')]
    public function testSetAndRetrieveData(mixed $data): void
    {
        $response = new JsonResponse($data);

        $this->assertInstanceOf(stdClass::class, $response->getData());
        $this->assertSame('bar', $response->getData()->foo);
    }

    public static function setAndRetrieveDataProvider(): array
    {
        return [
            'Jsonable data' => [new JsonResponseTestJsonableObject],
            'JsonSerializable data' => [new JsonResponseTestJsonSerializeObject],
            'Arrayable data' => [new JsonResponseTestArrayableObject],
            'Array data' => [['foo' => 'bar']],
            'stdClass data' => [(object) ['foo' => 'bar']],
        ];
    }

    public function testGetOriginalContent(): void
    {
        $response = new JsonResponse(new JsonResponseTestArrayableObject);
        $this->assertInstanceOf(JsonResponseTestArrayableObject::class, $response->getOriginalContent());

        $response = new JsonResponse;
        $response->setData(new JsonResponseTestArrayableObject);
        $this->assertInstanceOf(JsonResponseTestArrayableObject::class, $response->getOriginalContent());
    }

    public function testSetAndRetrieveOptions(): void
    {
        $response = new JsonResponse(['foo' => 'bar']);
        $response->setEncodingOptions(JSON_PRETTY_PRINT);
        $this->assertSame(JSON_PRETTY_PRINT, $response->getEncodingOptions());
    }

    public function testSetAndRetrieveDefaultOptions(): void
    {
        $response = new JsonResponse(['foo' => 'bar']);
        $this->assertSame(0, $response->getEncodingOptions());
    }

    public function testSetAndRetrieveStatusCode(): void
    {
        $response = new JsonResponse(['foo' => 'bar'], 404);
        $this->assertSame(404, $response->getStatusCode());

        $response = new JsonResponse(['foo' => 'bar']);
        $response->setStatusCode(404);
        $this->assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('jsonErrorDataProvider')]
    public function testInvalidArgumentExceptionOnJsonError(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonResponse(['data' => $data]);
    }

    #[DataProvider('jsonErrorDataProvider')]
    public function testGracefullyHandledSomeJsonErrorsWithPartialOutputOnError(mixed $data): void
    {
        new JsonResponse(['data' => $data], 200, [], JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public static function jsonErrorDataProvider(): array
    {
        // Resources can't be encoded
        $resource = tmpfile();

        // Recursion can't be encoded
        $recursiveObject = new stdClass;
        $objectB = new stdClass;
        $recursiveObject->b = $objectB;
        $objectB->a = $recursiveObject;

        // NAN or INF can't be encoded
        $nan = NAN;

        return [
            [$resource],
            [$recursiveObject],
            [$nan],
        ];
    }

    public function testFromJsonString(): void
    {
        $jsonString = '{"foo":"bar"}';
        $response = JsonResponse::fromJsonString($jsonString);

        $this->assertSame('bar', $response->getData()->foo);
    }
}

class JsonResponseTestJsonableObject implements Jsonable
{
    public function toJson(int $options = 0): string
    {
        return '{"foo":"bar"}';
    }
}

class JsonResponseTestJsonSerializeObject implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['foo' => 'bar'];
    }
}

class JsonResponseTestArrayableObject implements Arrayable
{
    public function toArray(): array
    {
        return ['foo' => 'bar'];
    }
}
