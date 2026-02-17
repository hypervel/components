<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Server;

use Swoole\Http\Request;
use Swoole\Http\Response;

interface OnRequestInterface
{
    /**
     * Handle an incoming HTTP request.
     */
    public function onRequest(Request $request, Response $response): void;
}
