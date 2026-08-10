<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use GuzzleHttp\Psr7\Request as Psr7Request;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;

class ApiRequestTest extends TestCase
{
    private ApiRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $psrRequest = new Psr7Request('POST', 'https://api.example.com');
        $this->request = new ApiRequest($psrRequest);
    }

    public function testWithMethod(): void
    {
        $request = $this->request->withMethod('POST');
        $this->assertSame('POST', $request->toPsrRequest()->getMethod());
    }

    public function testWithUrl(): void
    {
        $newUrl = 'https://api.example.com/users';
        $request = $this->request->withUrl($newUrl);
        $this->assertSame($newUrl, (string) $request->toPsrRequest()->getUri());

        // Test with callable
        $request = $this->request->withUrl(fn (string $url) => $url . '/posts');
        $this->assertSame('https://api.example.com/users/posts', (string) $request->toPsrRequest()->getUri());

        $request = $this->request->withUrl('trim');
        $this->assertSame('trim', (string) $request->toPsrRequest()->getUri());
    }

    public function testWithHeader(): void
    {
        $request = $this->request->withHeader('X-Test', 'value');
        $this->assertTrue($request->toPsrRequest()->hasHeader('X-Test'));
        $this->assertSame(['value'], $request->toPsrRequest()->getHeader('X-Test'));

        $request->withHeader('X-Test', ['first', 'second']);
        $this->assertSame(['first', 'second'], $request->toPsrRequest()->getHeader('X-Test'));
    }

    public function testWithHeaders(): void
    {
        $headers = [
            'X-Test-1' => 'value1',
            'X-Test-2' => 'value2',
        ];
        $request = $this->request->withHeaders($headers);

        foreach ($headers as $key => $value) {
            $this->assertTrue($request->toPsrRequest()->hasHeader($key));
            $this->assertSame([$value], $request->toPsrRequest()->getHeader($key));
        }
    }

    public function testWithAddedHeader(): void
    {
        $request = $this->request
            ->withHeader('X-Test', 'value1')
            ->withAddedHeader('X-Test', 'value2');

        $this->assertSame(['value1', 'value2'], $request->toPsrRequest()->getHeader('X-Test'));
    }

    public function testWithAddedHeaders(): void
    {
        $request = $this->request
            ->withHeaders(['X-Test' => 'value1'])
            ->withAddedHeaders(['X-Test' => 'value2']);

        $this->assertSame(['value1', 'value2'], $request->toPsrRequest()->getHeader('X-Test'));
    }

    public function testWithoutHeader(): void
    {
        $request = $this->request
            ->withHeader('X-Test', 'value')
            ->withoutHeader('X-Test');

        $this->assertFalse($request->toPsrRequest()->hasHeader('X-Test'));
    }

    public function testWithoutHeaders(): void
    {
        $request = $this->request
            ->withHeaders([
                'X-Test-1' => 'value1',
                'X-Test-2' => 'value2',
            ])
            ->withoutHeaders(['X-Test-1', 'X-Test-2']);

        $this->assertFalse($request->toPsrRequest()->hasHeader('X-Test-1'));
        $this->assertFalse($request->toPsrRequest()->hasHeader('X-Test-2'));
    }

    public function testWithBody(): void
    {
        $body = 'test body content';
        $request = $this->request->withBody($body);

        $psrRequest = $request->toPsrRequest();
        $this->assertSame($body, (string) $psrRequest->getBody());
        $this->assertTrue($psrRequest->getBody()->isSeekable());
        $this->assertSame([(string) strlen($body)], $psrRequest->getHeader('Content-Length'));
    }

    public function testJsonBodyMayBeMutatedAfterRawReplacement(): void
    {
        $request = $this->request
            ->withBody('{"first":1}')
            ->contentType('application/json')
            ->mergeData(['second' => 2]);

        $this->assertSame('{"first":1,"second":2}', (string) $request->toPsrRequest()->getBody());
    }

    public function testWithData(): void
    {
        $data = ['key' => 'value'];
        $request = $this->request
            ->withData($data);

        $psrRequest = $request->toPsrRequest();

        $this->assertSame(json_encode($data), (string) $psrRequest->getBody());
        $this->assertSame(['application/json'], $psrRequest->getHeader('Content-Type'));
    }

    public function testMergeData(): void
    {
        $request = new ApiRequest(new Psr7Request(
            'POST',
            'https://api.example.com',
            ['Content-Type' => 'application/json'],
            '{"first":1}'
        ));

        $request->mergeData(['second' => 2]);

        $this->assertSame('{"first":1,"second":2}', (string) $request->toPsrRequest()->getBody());
    }

    public function testWithoutData(): void
    {
        $request = new ApiRequest(new Psr7Request(
            'POST',
            'https://api.example.com',
            ['Content-Type' => 'application/json'],
            '{"key1":"value1","key2":"value2","meta":{"trace_id":"trace-1","keep":true}}'
        ));

        $request->withoutData(['key1', 'meta.trace_id']);

        $psrRequest = $request->toPsrRequest();
        $this->assertSame(
            json_encode(['key2' => 'value2', 'meta' => ['keep' => true]]),
            (string) $psrRequest->getBody(),
        );
    }

    public function testAsForm(): void
    {
        $request = $this->request->asForm();

        $this->assertSame(['application/x-www-form-urlencoded'], $request->toPsrRequest()->getHeader('Content-Type'));
    }

    public function testAsJson(): void
    {
        $request = $this->request->asJson();

        $this->assertSame(['application/json'], $request->toPsrRequest()->getHeader('Content-Type'));
    }

    public function testAsJsonPreservesAnExistingJsonMediaType(): void
    {
        $request = new ApiRequest(new Psr7Request(
            'POST',
            'https://api.example.com',
            ['Content-Type' => 'application/vnd.api+json; charset=utf-8'],
            '{"name":"Taylor"}',
        ));

        $request->asJson();

        $this->assertSame(
            ['application/vnd.api+json; charset=utf-8'],
            $request->toPsrRequest()->getHeader('Content-Type'),
        );
    }

    public function testStructuredFormatsConvertTheExistingData(): void
    {
        $request = new ApiRequest(new Psr7Request(
            'POST',
            'https://api.example.com',
            ['Content-Type' => 'application/json'],
            '{"name":"Taylor"}'
        ));

        $request->asForm();

        $this->assertSame('name=Taylor', (string) $request->toPsrRequest()->getBody());

        $request->asJson();

        $this->assertSame('{"name":"Taylor"}', (string) $request->toPsrRequest()->getBody());
    }

    #[DataProvider('structuredMutationOnReadMethodProvider')]
    public function testStructuredMutationIsRejectedForGetAndHead(
        string $httpMethod,
        string $mutation,
        array|string $argument,
    ): void {
        $request = new ApiRequest(new Psr7Request($httpMethod, 'https://api.example.com'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use withQuery() or withoutQuery() instead.');

        $request->{$mutation}($argument);
    }

    public static function structuredMutationOnReadMethodProvider(): array
    {
        return [
            'GET withData' => ['GET', 'withData', ['name' => 'Taylor']],
            'GET mergeData' => ['GET', 'mergeData', ['name' => 'Taylor']],
            'GET withoutData' => ['GET', 'withoutData', 'name'],
            'HEAD withData' => ['HEAD', 'withData', ['name' => 'Taylor']],
            'HEAD mergeData' => ['HEAD', 'mergeData', ['name' => 'Taylor']],
            'HEAD withoutData' => ['HEAD', 'withoutData', 'name'],
        ];
    }

    public function testRawGetBodiesRemainSupported(): void
    {
        $request = (new ApiRequest(new Psr7Request('GET', 'https://api.example.com')))
            ->withBody('raw body');

        $this->assertSame('raw body', (string) $request->toPsrRequest()->getBody());
    }

    #[DataProvider('unrepresentableBodyProvider')]
    public function testStructuredFormatConversionRejectsUnrepresentableBodies(ApiRequest $request): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not contain structured JSON or form data');

        $request->asJson();
    }

    public static function unrepresentableBodyProvider(): array
    {
        return [
            'raw' => [(new ApiRequest(new Psr7Request('POST', 'https://api.example.com')))->withBody('raw')],
            'multipart' => [new ApiRequest(new Psr7Request(
                'POST',
                'https://api.example.com',
                ['Content-Type' => 'multipart/form-data; boundary=test'],
                '--test--'
            ))],
            'unknown' => [new ApiRequest(new Psr7Request(
                'POST',
                'https://api.example.com',
                ['Content-Type' => 'application/xml'],
                '<data />'
            ))],
        ];
    }

    public function testInvalidJsonDataThrowsTheOriginalException(): void
    {
        $this->expectException(JsonException::class);

        $this->request->withData(['value' => NAN])->toPsrRequest();
    }

    public function testTransferEncodingRemovesContentLength(): void
    {
        $request = $this->request
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withHeader('Content-Length', '999')
            ->withBody('body')
            ->toPsrRequest();

        $this->assertSame(['chunked'], $request->getHeader('Transfer-Encoding'));
        $this->assertFalse($request->hasHeader('Content-Length'));
    }

    public function testAcceptJson(): void
    {
        $request = $this->request->acceptJson();

        $this->assertSame(['application/json'], $request->toPsrRequest()->getHeader('Accept'));
    }

    public function testAccept(): void
    {
        $contentType = 'application/xml';
        $request = $this->request->accept($contentType);

        $this->assertSame([$contentType], $request->toPsrRequest()->getHeader('Accept'));
    }

    public function testWithToken(): void
    {
        $request = $this->request->withToken('test-token');

        $this->assertSame(['Bearer test-token'], $request->toPsrRequest()->getHeader('Authorization'));
    }

    public function testWithUserAgent(): void
    {
        $userAgent = 'MyApiClient/1.0';
        $request = $this->request->withUserAgent($userAgent);

        $this->assertSame([$userAgent], $request->toPsrRequest()->getHeader('User-Agent'));

        $request->withUserAgent(false);
        $this->assertSame([''], $request->toPsrRequest()->getHeader('User-Agent'));
    }

    public function testCreateFromPreservesDataAndAttributes(): void
    {
        $body = m::mock(StreamInterface::class);
        $body->shouldReceive('__toString')->once()->andReturn('[]');
        $request = (new HttpRequest(new Psr7Request(
            'POST',
            'https://api.example.com',
            ['Content-Type' => 'application/json'],
            $body
        )))
            ->setRequestAttributes(['trace' => 'request-1']);
        $this->assertSame([], $request->data());

        $apiRequest = ApiRequest::createFrom($request);

        $this->assertSame([], $apiRequest->data());
        $this->assertSame(['trace' => 'request-1'], $apiRequest->attributes());
    }

    public function testCreateFromPreservesMultipartFileMetadata(): void
    {
        $request = (new HttpRequest(new Psr7Request(
            'POST',
            'https://api.example.com',
            ['Content-Type' => 'multipart/form-data; boundary=test'],
            '--test--'
        )))->withData([[
            'name' => 'photo',
            'contents' => 'contents',
            'filename' => 'photo.jpg',
        ]]);

        $apiRequest = ApiRequest::createFrom($request);

        $this->assertTrue($apiRequest->hasFile('photo', 'contents', 'photo.jpg'));
    }

    public function testWithQuery(): void
    {
        $request = $this->request->withQuery(['param1' => 'value1', 'param2' => 'value2']);

        $this->assertSame('param1=value1&param2=value2', $request->toPsrRequest()->getUri()->getQuery());
    }

    public function testWithoutQuery(): void
    {
        $request = $this->request->withQuery(['param1' => 'value1', 'param2' => 'value2']);
        $request->withoutQuery(['param1']);

        $this->assertSame('param2=value2', $request->toPsrRequest()->getUri()->getQuery());
    }
}
