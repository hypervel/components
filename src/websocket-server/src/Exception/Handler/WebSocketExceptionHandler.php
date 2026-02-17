<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exception\Handler;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Http\ResponsePlusInterface;
use Hypervel\ExceptionHandler\ExceptionHandler;
use Hypervel\ExceptionHandler\Formatter\FormatterInterface;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Stream\SwooleStream;
use Throwable;

class WebSocketExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger, protected FormatterInterface $formatter)
    {
    }

    public function handle(Throwable $throwable, ResponsePlusInterface $response)
    {
        $this->logger->warning($this->formatter->format($throwable));
        if ($throwable instanceof HttpException) {
            $response = $response->setStatus($throwable->getStatusCode());
        }
        $stream = new SwooleStream($throwable->getMessage());
        return $response->setBody($stream);
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
