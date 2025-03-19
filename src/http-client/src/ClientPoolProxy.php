<?php

declare(strict_types=1);

namespace Hypervel\HttpClient;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\ObjectPool\PoolProxy;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ClientPoolProxy extends PoolProxy implements ClientInterface
{
    /**
     * Asynchronously send an HTTP request.
     */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Send an HTTP request.
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * The HttpClient PSR (PSR-18) specify this method.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Create and send an asynchronous HTTP request.
     * @param mixed $uri
     */
    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Create and send an HTTP request.
     * @param mixed $uri
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get a client configuration option.
     */
    public function getConfig(?string $option = null)
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }
}
