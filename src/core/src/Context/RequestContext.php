<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hyperf\Context\RequestContext as HyperfRequestContext;
use Psr\Http\Message\ServerRequestInterface;

class RequestContext extends HyperfRequestContext
{
    public static function destroy(?int $coroutineId = null): void
    {
        Context::destroy(ServerRequestInterface::class, $coroutineId);
    }
}
