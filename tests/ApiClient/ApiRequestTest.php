<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use GuzzleHttp\Psr7\Request as Psr7Request;
use Hypervel\ApiClient\ApiRequest;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @covers \Hypervel\ApiClient\ApiRequest
 */
class ApiRequestTest extends TestCase
{
    private ApiRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $psrRequest = new Psr7Request('GET', 'https://api.example.com');
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
    }

    public function testWithHeader(): void
    {
        $request = $this->request->withHeader('X-Test', 'value');
        $this->assertTrue($request->toPsrRequest()->hasHeader('X-Test'));
        $this->assertSame(['value'], $request->toPsrRequest()->getHeader('X-Test'));
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
        $this->assertSame($body, $psrRequest->getBody()->getContents());
    }

    public function testWithData(): void
    {
        $data = ['key' => 'value'];
        $request = $this->request
            ->withHeader('Content-Type', 'application/json')
            ->withData($data);

        $psrRequest = $request->toPsrRequest();

        $this->assertSame(json_encode($data), $psrRequest->getBody()->getContents());
    }

    public function testWithoutData(): void
    {
        $request = $this->request
            ->withHeader('Content-Type', 'application/json')
            ->withData(['key1' => 'value1', 'key2' => 'value2'])
            ->withoutData(['key1']);

        $psrRequest = $request->toPsrRequest();
        $this->assertSame(json_encode(['key2' => 'value2']), $psrRequest->getBody()->getContents());
    }

    public function testAsForm(): void
    {
        $request = $this->request->asForm();

        $this->assertSame(['application/x-www-form-urlencoded'], $request->toPsrRequest()->getHeader('Content-Type'));
    }
}
