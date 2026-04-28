<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exceptions\Handler;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class WebSocketExceptionHandler
{
    public function __construct(
        protected StdoutLoggerInterface $logger,
    ) {
    }

    /**
     * Handle the exception and return a response.
     */
    public function handle(Throwable $throwable, Response $response): Response
    {
        $this->logger->warning((string) $throwable);

        $statusCode = $throwable instanceof HttpException
            ? $throwable->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        return new Response($throwable->getMessage(), $statusCode);
    }
}
