<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer\Stub;

use Hypervel\HttpServer\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

class ServerStub extends Server
{
    public function initRequestAndResponse(SwooleRequest $request, SwooleResponse $response): array
    {
        return parent::initRequestAndResponse($request, $response);
    }
}
