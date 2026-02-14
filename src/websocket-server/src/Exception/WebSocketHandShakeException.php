<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Exception;

use Hyperf\HttpMessage\Exception\BadRequestHttpException;

class WebSocketHandShakeException extends BadRequestHttpException
{
}
