<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exception;

use Hypervel\HttpMessage\Exceptions\BadRequestHttpException;

class WebSocketHandShakeException extends BadRequestHttpException
{
}
