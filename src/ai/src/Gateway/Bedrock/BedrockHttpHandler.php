<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\TransferStats;
use Hypervel\Ai\AiManager;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\RequestException as HttpRequestException;
use Psr\Http\Message\RequestInterface;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Send AWS requests through the framework's synchronous coroutine transport.
 *
 * @internal
 */
class BedrockHttpHandler
{
    /**
     * Create an AWS HTTP handler.
     */
    public function __construct(
        protected Factory $http,
        protected string $connection = AiManager::HTTP_CONNECTION,
    ) {
    }

    /**
     * Send a signed request and return the settled AWS HTTP result.
     *
     * @throws CanceledException
     */
    public function __invoke(RequestInterface $request, array $options = []): PromiseInterface
    {
        try {
            $pending = $this->http->createPendingRequest()->connection($this->connection);
            $receiver = $options['http_stats_receiver'] ?? null;
            $onStats = $options['on_stats'] ?? $pending->getOptions()['on_stats'] ?? null;
            unset($options['http_stats_receiver'], $options['on_stats']);

            if ($receiver !== null || $onStats !== null) {
                // Keep PendingRequest's collector and its response statistics in the call chain.
                $options['on_stats'] = static function (TransferStats $stats) use ($receiver, $onStats): void {
                    if ($onStats !== null) {
                        $onStats($stats);
                    }

                    if ($receiver !== null) {
                        $receiver(['total_time' => $stats->getTransferTime()] + $stats->getHandlerStats());
                    }
                };
            }

            $response = $pending->withOptions($options)
                ->withBody($request->getBody(), null)
                ->send($request->getMethod(), (string) $request->getUri(), [
                    'headers' => $request->getHeaders(),
                    'version' => $request->getProtocolVersion(),
                ])->toPsrResponse();

            if ($response->getStatusCode() >= 400) {
                return new RejectedPromise([
                    'exception' => RequestException::create($request, $response),
                    'response' => $response,
                    'connection_error' => false,
                ]);
            }

            return new FulfilledPromise($response);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $response = $exception instanceof HttpRequestException ? $exception->response->toPsrResponse() : null;

            if ($exception instanceof ConnectionException && $exception->getPrevious() !== null) {
                $exception = $exception->getPrevious();
            }

            if ($exception instanceof ResponseException) {
                $response = $exception->getResponse();
            } elseif ($exception instanceof RequestException && is_callable([$exception, 'getResponse'])) {
                $response = $exception->getResponse();
            }

            return new RejectedPromise([
                'exception' => $exception,
                'response' => $response,
                'connection_error' => $exception instanceof ConnectException
                    || $exception instanceof NetworkException
                    || $exception instanceof ResponseTransferException
                    || ($exception instanceof RequestException
                        && is_callable([$exception, 'getHandlerContext'])
                        && ($exception->getHandlerContext()['errno'] ?? null) === 56),
            ]);
        }
    }
}
