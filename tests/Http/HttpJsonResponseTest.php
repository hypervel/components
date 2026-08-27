<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Http\JsonResponse;
use Hypervel\Support\Json;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;
use Symfony\Component\HttpFoundation\JsonResponse as SymfonyJsonResponse;
use TypeError;

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

    #[DataProvider('rawJsonDataProvider')]
    public function testRawJsonRetainsSymfonyConstructorCompatibility(mixed $data): void
    {
        $expected = new SymfonyJsonResponse($data, 201, ['X-Test' => 'value'], true);
        $response = new JsonResponse($data, 201, ['X-Test' => 'value'], json: true);

        $this->assertSame($expected->getContent(), $response->getContent());
        $this->assertSame($expected->getStatusCode(), $response->getStatusCode());
        $this->assertSame('value', $response->headers->get('X-Test'));
    }

    public static function rawJsonDataProvider(): array
    {
        return [
            'string' => ['{"foo":"bar"}'],
            'integer' => [123],
            'float' => [12.5],
            'Stringable' => [new JsonResponseTestStringableObject],
        ];
    }

    #[DataProvider('invalidRawJsonDataProvider')]
    public function testRawJsonRejectsValuesSymfonyDoesNotAccept(mixed $data): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('If $json is set to true');

        new JsonResponse($data, json: true);
    }

    public static function invalidRawJsonDataProvider(): array
    {
        return [
            'null' => [null],
            'array' => [[]],
            'ordinary object' => [new stdClass],
        ];
    }

    public function testDataRoundTripsAtTheMaximumSupportedNestingDepth(): void
    {
        $value = 'leaf';

        for ($index = 1; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $response = new JsonResponse(['nested' => $value]);

        $this->assertSame(['nested' => $value], $response->getData(assoc: true));

        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES);

        $this->assertSame(['nested' => $value], $response->getData(assoc: true));
        $this->assertNotSame('null', $response->getContent());
    }

    public function testDataRejectsOneLevelOverTheMaximumNestingDepth(): void
    {
        $value = 'leaf';

        for ($index = 0; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $this->expectException(InvalidArgumentException::class);

        new JsonResponse(['nested' => $value]);
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

class JsonResponseTestStringableObject implements Stringable
{
    public function __toString(): string
    {
        return '{"foo":"bar"}';
    }
}
