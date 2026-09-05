<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Hypervel\Contracts\Engine\Http\ClientInterface as EngineClientInterface;
use Hypervel\Engine\Exceptions\HttpClientBusyException;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Hypervel\Engine\Exceptions\RuntimeException as EngineRuntimeException;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketConnectException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Hypervel\Engine\Http\Client as EngineClient;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\ObjectPool\Exceptions\PoolExhaustedException;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Support\Sleep;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class TransportHandler
{
    public const SUPPORTED_TRANSPORTS = ['auto', 'swoole', 'curl'];

    private const GUZZLE_CONNECT_TIMEOUT_EXCEPTION = 'GuzzleHttp\\Exception\\ConnectTimeoutException';

    private const GUZZLE_NETWORK_EXCEPTION = 'GuzzleHttp\\Exception\\NetworkException';

    private const GUZZLE_NETWORK_TIMEOUT_EXCEPTION = 'GuzzleHttp\\Exception\\NetworkTimeoutException';

    private Closure $fallbackHandler;

    private SwooleHandler $swooleHandler;

    /** @var array<string, ObjectPool> */
    private array $ownedPools = [];

    private bool $closed = false;

    /**
     * Create an HTTP transport handler.
     */
    public function __construct(
        private PoolFactory $poolFactory,
        private string $logicalIdentity,
        private PoolOptions $poolOptions,
        private string $transport = 'curl',
        array $handlerOptions = [],
        ?SwooleHandler $swooleHandler = null,
        ?callable $fallbackHandler = null,
    ) {
        if (trim($this->logicalIdentity) === '') {
            throw new InvalidArgumentException('The HTTP transport identity must be a non-empty string.');
        }

        $this->validateTransport($this->transport);
        $this->swooleHandler = $swooleHandler ?? new SwooleHandler;
        $this->fallbackHandler = Closure::fromCallable(
            $fallbackHandler ?? Utils::chooseHandler($handlerOptions),
        );
    }

    /**
     * Handle a request with the configured transport.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        return $this->handleUsing($this->transport, $request, $options);
    }

    /**
     * Handle a request with an explicit transport.
     */
    public function handleUsing(
        string $transport,
        RequestInterface $request,
        array $options,
    ): PromiseInterface {
        if ($this->closed) {
            return Create::rejectionFor(new RuntimeException('The HTTP transport handler is closed.'));
        }

        try {
            $this->validateTransport($transport);
        } catch (Throwable $exception) {
            return Create::rejectionFor($exception);
        }

        if ($transport === 'curl') {
            return $this->handleFallback($request, $options);
        }

        try {
            $prepared = $this->swooleHandler->prepare($request, $options);
        } catch (Throwable $exception) {
            return Create::rejectionFor($exception);
        }

        if (is_string($prepared)) {
            if ($transport === 'auto') {
                return $this->handleFallback($request, $options);
            }

            return Create::rejectionFor(new UnsupportedTransportException(
                "The Swoole HTTP transport cannot handle this request because {$prepared}.",
            ));
        }

        return $this->handleNative($request, $options, $prepared);
    }

    /**
     * Close every pool owned by this handler.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $pools = $this->ownedPools;
        $this->ownedPools = [];

        foreach ($pools as $identity => $pool) {
            $this->poolFactory->remove($identity, $pool);
        }
    }

    /**
     * Handle a request with Guzzle's selected fallback.
     */
    private function handleFallback(RequestInterface $request, array $options): PromiseInterface
    {
        if (isset($options['on_stats'])) {
            $onStats = $options['on_stats'];
            $options['on_stats'] = static function (TransferStats $stats) use ($onStats): void {
                $onStats(new TransferStats(
                    $stats->getRequest(),
                    $stats->getResponse(),
                    $stats->getTransferTime(),
                    $stats->getHandlerErrorData(),
                    array_merge($stats->getHandlerStats(), ['transport' => 'guzzle']),
                ));
            };
        }

        return ($this->fallbackHandler)($request, $options);
    }

    /**
     * Handle a request with a pooled Engine client.
     */
    private function handleNative(
        RequestInterface $request,
        array $options,
        SwooleRequest $prepared,
    ): PromiseInterface {
        $startedAt = hrtime(true);
        $pool = null;
        $client = null;

        try {
            if ($prepared->delayMicroseconds > 0) {
                Sleep::usleep($prepared->delayMicroseconds);
            }

            $pool = $this->getPool($prepared);
            $client = $pool->get();

            /** @var EngineClientInterface $client */
            $response = $this->swooleHandler->send($client, $prepared, $options);
        } catch (Throwable $exception) {
            if ($pool !== null && $client instanceof EngineClientInterface) {
                $this->settle($pool, $client, $exception instanceof EngineRuntimeException);
            }

            $mappedException = $this->mapException($exception, $request);

            try {
                $this->invokeStats($options, $request, $startedAt, error: $exception);
            } catch (Throwable $statsException) {
                return Create::rejectionFor($statsException);
            }

            return Create::rejectionFor($mappedException);
        }

        try {
            $this->settle($pool, $client);
            $this->invokeStats($options, $request, $startedAt, $response);
        } catch (Throwable $exception) {
            return Create::rejectionFor($exception);
        }

        return Create::promiseFor($response);
    }

    /**
     * Get the pool for a prepared physical connection.
     */
    private function getPool(SwooleRequest $request): ObjectPool
    {
        $host = $request->host;
        $port = $request->port;
        $ssl = $request->ssl;
        $constructionSettings = $request->constructionSettings;
        $fingerprint = PoolFingerprint::fromConfig([
            'host' => $host,
            'port' => $port,
            'ssl' => $ssl,
            'construction_settings' => $constructionSettings,
        ]);
        $identity = "http:{$this->logicalIdentity}:{$fingerprint}";
        $pool = $this->poolFactory->getOrCreate(
            new PoolDefinition(
                identity: $identity,
                resourceType: 'http',
                fingerprint: $fingerprint,
                options: $this->poolOptions,
            ),
            fn (): EngineClientInterface => $this->createClient(
                $host,
                $port,
                $ssl,
                $constructionSettings,
            ),
        );

        $this->ownedPools[$identity] = $pool;

        return $pool;
    }

    /**
     * Create a fresh Engine client for a pool.
     */
    protected function createClient(
        string $host,
        int $port,
        bool $ssl,
        array $constructionSettings,
    ): EngineClientInterface
    {
        return new EngineClient(
            $host,
            $port,
            $ssl,
            $constructionSettings,
        );
    }

    /**
     * Settle a borrowed Engine client.
     */
    private function settle(
        ObjectPool $pool,
        EngineClientInterface $client,
        bool $unsafe = false,
    ): void {
        if ($unsafe || ! $client->isConnected()) {
            $pool->discard($client);

            return;
        }

        $pool->release($client);
    }

    /**
     * Map a native failure to the installed Guzzle major.
     */
    private function mapException(Throwable $exception, RequestInterface $request): Throwable
    {
        // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8;
        // import its exception types directly and delete availableExceptionClass().
        if ($exception instanceof PoolExhaustedException) {
            return new ConnectException($exception->getMessage(), $request, previous: $exception);
        }

        if ($exception instanceof HttpClientBusyException
            || $exception instanceof RunningInNonCoroutineException) {
            return $exception;
        }

        if ($exception instanceof SocketConnectException) {
            $exceptionClass = $exception->getCode() === SOCKET_ETIMEDOUT
                ? $this->availableExceptionClass(self::GUZZLE_CONNECT_TIMEOUT_EXCEPTION)
                : null;

            return $exceptionClass === null
                ? new ConnectException($exception->getMessage(), $request, previous: $exception)
                : new $exceptionClass($exception->getMessage(), $request, previous: $exception);
        }

        if ($exception instanceof SocketTimeoutException) {
            if (($exceptionClass = $this->availableExceptionClass(self::GUZZLE_NETWORK_TIMEOUT_EXCEPTION)) !== null) {
                return new $exceptionClass(
                    $exception->getMessage(),
                    $request,
                    previous: $exception,
                );
            }

            return new ConnectException($exception->getMessage(), $request, previous: $exception);
        }

        if ($exception instanceof SocketClosedException) {
            if (($exceptionClass = $this->availableExceptionClass(self::GUZZLE_NETWORK_EXCEPTION)) !== null) {
                return new $exceptionClass(
                    $exception->getMessage(),
                    $request,
                    previous: $exception,
                );
            }

            return new GuzzleRequestException(
                $exception->getMessage(),
                $request,
                previous: $exception,
            );
        }

        if ($exception instanceof HttpClientException) {
            return new GuzzleRequestException(
                $exception->getMessage(),
                $request,
                previous: $exception,
            );
        }

        return $exception;
    }

    /**
     * Resolve an exception class available only on newer Guzzle majors.
     *
     * @return null|class-string<Throwable>
     */
    private function availableExceptionClass(string $exceptionClass): ?string
    {
        return class_exists($exceptionClass) && is_subclass_of($exceptionClass, Throwable::class)
            ? $exceptionClass
            : null;
    }

    /**
     * Invoke a native transfer statistics callback.
     */
    private function invokeStats(
        array $options,
        RequestInterface $request,
        int $startedAt,
        ?ResponseInterface $response = null,
        mixed $error = null,
    ): void {
        if (! isset($options['on_stats'])) {
            return;
        }

        $options['on_stats'](new TransferStats(
            $request,
            $response,
            (hrtime(true) - $startedAt) / 1e9,
            $error,
            ['transport' => 'swoole'],
        ));
    }

    /**
     * Validate an HTTP transport name.
     */
    private function validateTransport(string $transport): void
    {
        if (! in_array($transport, self::SUPPORTED_TRANSPORTS, true)) {
            throw new InvalidArgumentException(
                "Unsupported HTTP transport [{$transport}]. Supported transports are ["
                . implode(', ', self::SUPPORTED_TRANSPORTS)
                . '].',
            );
        }
    }
}
