<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use GuzzleHttp\Cookie\SetCookie;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\RequestException as HttpRequestException;
use Psr\Http\Message\RequestInterface;

class Sender
{
    /**
     * Create the Saloon sender.
     */
    public function __construct(
        protected Factory $http,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Send a prepared Saloon request through Hypervel HTTP.
     *
     * @template TDto
     * @param PendingRequest<TDto> $pendingRequest
     * @param array{connection: string, connectionOptions: array<string, mixed>, requestOptions: array<string, mixed>} $transport
     * @return Response<TDto>
     */
    public function send(PendingRequest $pendingRequest, array $transport): Response
    {
        $connection = $transport['connection'];
        $connectionOptions = $transport['connectionOptions'];
        $options = $transport['requestOptions'];

        $tags = [
            TelescopeTag::Saloon,
            ...($connectionOptions['telescope_tags'] ?? []),
            ...($options['telescope_tags'] ?? []),
        ];
        $options['telescope_tags'] = $tags;
        $options['delay'] = 0;
        $options['http_errors'] = false;

        if (($authentication = $pendingRequest->transportAuthentication()) !== null) {
            $options['auth'] = $authentication;
        }

        if (($certificate = $pendingRequest->certificate()) !== null) {
            $options['cert'] = $certificate;
        }

        $httpRequest = $this->http->createPendingRequest()
            ->connection($connection)
            ->withOptions($options);

        if (($body = $pendingRequest->preparedBody()) !== null) {
            $httpRequest->withBody($body, null);
        }

        foreach ($pendingRequest->cookies() as $cookie) {
            $httpRequest->withCookie(new SetCookie($cookie));
        }

        $httpRequest
            ->withHeaders(HeaderNormalizer::normalize($pendingRequest->headers()))
            ->withRequestMiddleware(function (RequestInterface $request) use ($pendingRequest): RequestInterface {
                $request = $pendingRequest->handlePsrRequest($request);
                $pendingRequest->notifyPsrRequestObservers($request);
                $pendingRequest->setPsrRequest($request);

                return $request;
            });

        try {
            $httpResponse = $httpRequest->send(
                $pendingRequest->method()->value,
                (string) $pendingRequest->uri(),
            );
        } catch (HttpRequestException $exception) {
            // A transfer that fails after the response headers arrive keeps its partial response. The HTTP client
            // throws that as a request exception for a 4xx or 5xx status and as a connection exception otherwise, so
            // both reach Saloon as an exchange that could not complete.
            throw new ConnectionException($exception->getMessage(), 0, $exception);
        }

        return $pendingRequest->createResponse(
            $httpResponse,
            $pendingRequest->toPsrRequest(),
        );
    }

    /**
     * Resolve and validate the transport configuration for an operation.
     *
     * @return array{connection: string, connectionOptions: array<string, mixed>, requestOptions: array<string, mixed>}
     * @internal
     */
    public function resolveTransport(PendingRequest $pendingRequest): array
    {
        $connection = $this->resolveConnection($pendingRequest);
        $connectionOptions = $this->http->getConnectionOptions($connection);
        $requestOptions = $pendingRequest->options();

        RequestOptionValidator::validate($connectionOptions, "HTTP connection [{$connection}]");
        RequestOptionValidator::validate($requestOptions, 'Saloon request options');

        return [
            'connection' => $connection,
            'connectionOptions' => $connectionOptions,
            'requestOptions' => $requestOptions,
        ];
    }

    /**
     * Resolve the registered HTTP connection.
     */
    protected function resolveConnection(PendingRequest $pendingRequest): string
    {
        return $pendingRequest->connector()->resolveHttpConnection()
            ?? $this->config->string('saloon.connection.name');
    }
}
