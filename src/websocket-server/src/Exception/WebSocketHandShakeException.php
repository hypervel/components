<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class WebSocketHandShakeException extends BadRequestHttpException
{
}
