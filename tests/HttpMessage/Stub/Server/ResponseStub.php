<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage\Stub\Server;

use Hypervel\HttpMessage\Server\ResponseProxyTrait;
use Psr\Http\Message\ResponseInterface;

class ResponseStub implements ResponseInterface
{
    use ResponseProxyTrait;
}
