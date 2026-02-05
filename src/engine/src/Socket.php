<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Engine;

use Hyperf\Engine\Contract\Socket\SocketOptionInterface;
use Hyperf\Engine\Contract\SocketInterface;

class Socket extends \Swoole\Coroutine\Socket implements SocketInterface
{
    protected ?SocketOptionInterface $option = null;

    public function setSocketOption(SocketOptionInterface $option): void
    {
        $this->option = $option;
    }

    public function getSocketOption(): ?SocketOptionInterface
    {
        return $this->option;
    }
}
