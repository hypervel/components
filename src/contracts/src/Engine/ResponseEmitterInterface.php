<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

use Symfony\Component\HttpFoundation\Response;

interface ResponseEmitterInterface
{
    /**
     * @param mixed $connection swoole response or swow session
     */
    public function emit(Response $response, mixed $connection, bool $withContent = true): void;
}
