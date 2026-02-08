<?php

declare(strict_types=1);

namespace Hypervel\Guzzle;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Engine\Http\Client;
use Hypervel\Pool\Pool;
use Hypervel\Pool\SimplePool\PoolFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class PoolHandler extends CoroutineHandler
{
    /**
     * Create a new pool handler instance.
     *
     * @see Pool::initOption()
     */
    public function __construct(
        protected PoolFactory $factory,
        protected array $option = [],
        protected bool $isCookiePersistent = true,
    ) {
    }

    /**
     * Handle the HTTP request using a pooled connection.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort();
        $ssl = $uri->getScheme() === 'https';
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if (empty($port)) {
            $port = $ssl ? 443 : 80;
        }
        if (empty($path)) {
            $path = '/';
        }
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $pool = $this->factory->get(
            $this->getPoolName($uri),
            fn () => $this->makeClient($host, $port, $ssl),
            $this->option
        );

        $connection = $pool->get();
        $response = null;
        try {
            /** @var Client $client */
            $client = $connection->getConnection();
            if (! $this->isCookiePersistent) {
                $client->setCookies([]);
            }

            $headers = $this->initHeaders($request, $options);
            $settings = $this->getSettings($request, $options);
            if (! empty($settings)) {
                $client->set($settings);
            }

            $ms = microtime(true);

            try {
                $raw = $client->request($request->getMethod(), $path, $headers, (string) $request->getBody());
            } catch (Exception $exception) {
                $connection->close();
                $exception = new ConnectException($exception->getMessage(), $request, null, [
                    'errCode' => $exception->getCode(),
                ]);
                return Create::rejectionFor($exception);
            }

            $response = $this->getResponse($raw, $request, $options, microtime(true) - $ms);
        } finally {
            $connection->release();
        }

        return new FulfilledPromise($response);
    }

    /**
     * Get the pool name for the given URI.
     */
    protected function getPoolName(UriInterface $uri): string
    {
        return sprintf('guzzle.handler.%s.%d.%s', $uri->getHost(), $uri->getPort(), $uri->getScheme());
    }
}
