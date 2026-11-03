<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\RetryConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Throwable;

class Guzzle8SenderExceptionTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * @param Closure(RequestInterface): Throwable $makeException
     */
    #[DataProvider('transferFailures')]
    public function testGuzzle8TransferFailuresAreConvertedToFatalRequestExceptions(Closure $makeException): void
    {
        Http::fake(fn (HttpRequest $request): PromiseInterface => Create::rejectionFor($makeException($request->toPsrRequest())));

        $this->expectException(FatalRequestException::class);

        TestConnector::make()->send(new UserRequest);
    }

    /**
     * Get the Guzzle 8 transfer failures that end a request.
     *
     * @return array<string, array{Closure(RequestInterface): Throwable}>
     */
    public static function transferFailures(): array
    {
        return [
            'ConnectException' => [fn (RequestInterface $psrRequest) => new ConnectException('connect failed', $psrRequest)],
            'NetworkTimeoutException' => [fn (RequestInterface $psrRequest) => new NetworkTimeoutException('timed out before headers', $psrRequest)],
            'NetworkException' => [fn (RequestInterface $psrRequest) => new NetworkException('empty reply', $psrRequest)],
            'ResponseTimeoutException' => [fn (RequestInterface $psrRequest) => new ResponseTimeoutException(
                'timed out after headers',
                $psrRequest,
                new PsrResponse(200, [], '{"partial":'),
            )],
        ];
    }

    // REMOVED: the sendAsync() case. Coroutine pools replace promises.

    public function testGuzzle8NetworkFailuresParticipateInConnectorRetries(): void
    {
        $attempts = 0;
        Http::fake(function (HttpRequest $request) use (&$attempts): PromiseInterface {
            ++$attempts;

            return Create::rejectionFor(new NetworkException('empty reply', $request->toPsrRequest()));
        });

        try {
            (new RetryConnector(3))->send(new UserRequest);

            $this->fail('The network failure did not throw.');
        } catch (FatalRequestException) {
            $this->assertSame(3, $attempts);
        }
    }
}
