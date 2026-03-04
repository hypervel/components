<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exceptions\Handler;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\ExceptionHandler\ExceptionHandler;
use Hypervel\ExceptionHandler\Formatter\FormatterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class WebSocketExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger, protected FormatterInterface $formatter)
    {
    }

    /**
     * Handle the exception and return a response.
     */
    public function handle(Throwable $throwable, Response $response): Response
    {
        $this->logger->warning($this->formatter->format($throwable));

        $statusCode = $throwable instanceof HttpException
            ? $throwable->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        return new Response($throwable->getMessage(), $statusCode);
    }

    /**
     * Determine if this handler should handle the given exception.
     */
    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
