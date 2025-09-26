<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Hypervel\Http\Exceptions\PostTooLargeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ValidatePostSize implements MiddlewareInterface
{
    protected ?int $postMaxSize = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $max = $this->getPostMaxSize();

        if ($max > 0 && (int) $request->getHeaderLine('CONTENT_LENGTH') > $max) {
            throw new PostTooLargeException('The POST data is too large.');
        }

        return $handler->handle($request);
    }

    /**
     * Determine the server 'post_max_size' as bytes.
     */
    protected function getPostMaxSize(): int
    {
        if (! is_null($this->postMaxSize)) {
            return $this->postMaxSize;
        }

        if (is_numeric($postMaxSize = ini_get('post_max_size'))) {
            return (int) $this->postMaxSize;
        }

        $metric = strtoupper(substr($postMaxSize, -1));

        $postMaxSize = (int) $postMaxSize;

        return $this->postMaxSize = match ($metric) {
            'K' => $postMaxSize * 1024,
            'M' => $postMaxSize * 1048576,
            'G' => $postMaxSize * 1073741824,
            default => $postMaxSize,
        };
    }
}
