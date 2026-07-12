<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class WebSocketHandshakeException extends BadRequestHttpException
{
}
