<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

use Psr\Http\Message\ResponseInterface;

interface ResponseEmitterInterface
{
    /**
     * @param mixed $connection swoole response or swow session
     */
    public function emit(ResponseInterface $response, mixed $connection, bool $withContent = true): void;
}
