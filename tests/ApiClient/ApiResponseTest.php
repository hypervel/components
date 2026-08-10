<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\ApiClient\Exceptions\InvalidResourceDataException;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class ApiResponseTest extends TestCase
{
    public function testCreateFromPreservesHttpResponseState(): void
    {
        $decodeCount = 0;
        $psrResponse = new Psr7Response(500, body: '{"message":"failure"}');
        $response = (new HttpResponse($psrResponse))
            ->decodeUsing(function (string $body) use (&$decodeCount): array {
                ++$decodeCount;

                return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            })
            ->truncateExceptionsAt(5);
        $response->cookies = new CookieJar;
        $response->transferStats = new TransferStats(
            new Psr7Request('GET', 'https://api.example.com/final'),
            $psrResponse,
            handlerStats: ['primary_ip' => '127.0.0.1'],
        );
        $response->json();

        $apiResponse = ApiResponse::createFrom($response);

        $this->assertSame(1, $decodeCount);
        $this->assertSame(['message' => 'failure'], $apiResponse->json());
        $this->assertSame(1, $decodeCount);
        $this->assertSame($response->cookies, $apiResponse->cookies);
        $this->assertSame('https://api.example.com/final', (string) $apiResponse->effectiveUri());
        $this->assertSame(['primary_ip' => '127.0.0.1'], $apiResponse->handlerStats());
        $this->assertSame($response->toException()?->getMessage(), $apiResponse->toException()?->getMessage());
    }

    public function testWithBodyInvalidatesDecodedStateAndRetainsTheDecoder(): void
    {
        $decodeCount = 0;
        $response = (new ApiResponse(new Psr7Response(200, body: 'old')))
            ->decodeUsing(function (string $body) use (&$decodeCount): string {
                ++$decodeCount;

                return strtoupper($body);
            });

        $this->assertSame('OLD', $response->json());

        $response->withBody(Utils::streamFor('new'));

        $this->assertSame('NEW', $response->json());
        $this->assertSame(2, $decodeCount);
    }

    #[DataProvider('arrayBodyProvider')]
    public function testToArrayAcceptsArrayAndNullJsonBodies(string $body, array $expected): void
    {
        $response = new ApiResponse(new Psr7Response(200, body: $body));

        $this->assertSame($expected, $response->toArray());
    }

    public static function arrayBodyProvider(): array
    {
        return [
            'array' => ['{"name":"Taylor"}', ['name' => 'Taylor']],
            'empty' => ['', []],
            'whitespace' => [" \t\n\r", []],
            'null' => ['null', []],
            'padded null' => [" \tnull\r\n", []],
        ];
    }

    #[DataProvider('invalidArrayBodyProvider')]
    public function testToArrayRejectsNonArrayBodies(string $body): void
    {
        $response = new ApiResponse(new Psr7Response(200, body: $body));

        $this->expectException(InvalidResourceDataException::class);

        $response->toArray();
    }

    public static function invalidArrayBodyProvider(): array
    {
        return [
            'string' => ['"value"'],
            'integer' => ['1'],
            'boolean' => ['true'],
            'malformed' => ['not-json'],
            'null suffix' => ['nullx'],
            'non-json whitespace' => ["\v"],
        ];
    }

    public function testCustomDecoderNullMapsToAnEmptyArray(): void
    {
        $response = (new ApiResponse(new Psr7Response(200, body: 'custom')))
            ->decodeUsing(fn (): null => null);

        $this->assertSame([], $response->toArray());
    }

    public function testCustomDecoderObjectsAreNotRecursivelyConverted(): void
    {
        $response = (new ApiResponse(new Psr7Response(200, body: 'custom')))
            ->decodeUsing(fn (): stdClass => new stdClass);

        $this->expectException(InvalidResourceDataException::class);

        $response->toArray();
    }

    public function testConfiguredThrowingJsonFlagsRemainObservable(): void
    {
        HttpResponse::$defaultJsonDecodingFlags = JSON_THROW_ON_ERROR;
        $response = new ApiResponse(new Psr7Response(200, body: 'not-json'));

        $this->expectException(JsonException::class);

        $response->toArray();
    }

    public function testExistenceChecksDoNotRequireAnArrayBody(): void
    {
        $response = new ApiResponse(new Psr7Response(200, body: '"value"'));

        $this->assertFalse(isset($response['name']));

        $this->expectException(InvalidResourceDataException::class);

        $response['name'];
    }

    public function testGenericArrayableConsumersUseTheExplicitArrayContract(): void
    {
        $response = new ApiResponse(new Psr7Response(200, body: '{"name":"Taylor"}'));

        $this->assertSame(['name' => 'Taylor'], collect($response)->all());
    }
}
