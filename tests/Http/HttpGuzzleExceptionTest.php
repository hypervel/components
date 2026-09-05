<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Response;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class HttpGuzzleExceptionTest extends TestCase
{
    // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8;
    // delete the Guzzle 7 cases and use direct Guzzle 8 exception types without skips.

    /** @var Factory[] */
    private array $factories = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->factories as $factory) {
                $factory->purge();
            }
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('synchronousProvider')]
    public function testGuzzleSevenPostHeaderTransferFailureIsAConnectionFailure(bool $async): void
    {
        $request = new GuzzleRequest('GET', 'https://example.com/truncated');
        $response = new Psr7Response(200, ['Content-Length' => '10'], 'short');
        $exception = new GuzzleRequestException('transfer failed', $request, $response);

        [$result, $recordedResponse] = $this->sendFailure($exception, $async);

        $this->assertInstanceOf(ConnectionException::class, $result);
        $this->assertSame($exception, $result->getPrevious());
        $this->assertNull($recordedResponse);
    }

    #[DataProvider('synchronousProvider')]
    public function testGuzzleSevenCallbackFailureWithAPreviousThrowableKeepsItsResponse(bool $async): void
    {
        $request = new GuzzleRequest('GET', 'https://example.com/callback');
        $response = new Psr7Response(200, ['X-Complete' => 'yes'], 'complete');
        $exception = new GuzzleRequestException(
            'callback failed',
            $request,
            $response,
            new RuntimeException('callback cause'),
        );

        [$result, $recordedResponse] = $this->sendFailure($exception, $async);

        $this->assertInstanceOf($async ? Response::class : ConnectionException::class, $result);
        $this->assertInstanceOf(Response::class, $recordedResponse);
        $this->assertSame(200, $recordedResponse->status());
        $this->assertSame('complete', $recordedResponse->body());
    }

    public function testGuzzleSevenResponseExceptionSubclassKeepsItsResponse(): void
    {
        $request = new GuzzleRequest('GET', 'https://example.com/redirect');
        $response = new Psr7Response(301, ['Location' => '/next']);
        $exception = new TooManyRedirectsException('too many redirects', $request, $response);

        [$result, $recordedResponse] = $this->sendFailure($exception, false);

        $this->assertInstanceOf(ConnectionException::class, $result);
        $this->assertInstanceOf(Response::class, $recordedResponse);
        $this->assertSame(301, $recordedResponse->status());
    }

    #[DataProvider('synchronousProvider')]
    public function testGuzzleEightResponseTransferFailureIsAConnectionFailure(bool $async): void
    {
        $exceptionClass = 'GuzzleHttp\\Exception\\ResponseTransferException';

        if (! class_exists($exceptionClass)) {
            $this->markTestSkipped('Guzzle 8 response transfer exceptions are not installed.');
        }

        $request = new GuzzleRequest('GET', 'https://example.com/truncated');
        $response = new Psr7Response(200, ['Content-Length' => '10'], 'short');
        $exception = new $exceptionClass('transfer failed', $request, $response);

        [$result, $recordedResponse] = $this->sendFailure($exception, $async);

        $this->assertInstanceOf(ConnectionException::class, $result);
        $this->assertSame($exception, $result->getPrevious());
        $this->assertNull($recordedResponse);
    }

    #[DataProvider('synchronousProvider')]
    public function testGuzzleEightNetworkFailureIsAConnectionFailure(bool $async): void
    {
        $exceptionClass = 'GuzzleHttp\\Exception\\NetworkException';

        if (! class_exists($exceptionClass)) {
            $this->markTestSkipped('Guzzle 8 network exceptions are not installed.');
        }

        $request = new GuzzleRequest('GET', 'https://example.com/reset');
        $exception = new $exceptionClass('connection reset', $request);

        [$result, $recordedResponse] = $this->sendFailure($exception, $async);

        $this->assertInstanceOf(ConnectionException::class, $result);
        $this->assertSame($exception, $result->getPrevious());
        $this->assertNull($recordedResponse);
    }

    public static function synchronousProvider(): array
    {
        return [
            'synchronous' => [false],
            'asynchronous' => [true],
        ];
    }

    /**
     * Send a synthetic Guzzle failure through the public client pipeline.
     *
     * @return array{Throwable|Response, ?Response}
     */
    private function sendFailure(Throwable $failure, bool $async): array
    {
        $factory = new Factory;
        $factory->record();
        $this->factories[] = $factory;
        $request = $factory->setHandler(
            static fn () => Create::rejectionFor($failure)
        );

        if ($async) {
            $result = $request->async()->get('https://example.com')->wait();
        } else {
            try {
                $result = $request->get('https://example.com');
            } catch (Throwable $exception) {
                $result = $exception;
            }
        }

        $recorded = $factory->recorded()->first();

        return [$result, $recorded[1]];
    }
}
