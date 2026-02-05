<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\WebSocket\FrameInterface;

/**
 * Get Swoole WebSocket flags from a frame.
 *
 * @internal
 */
function swoole_get_flags_from_frame(FrameInterface $frame): int
{
    $flags = 0;
    if ($frame->getFin()) {
        $flags |= SWOOLE_WEBSOCKET_FLAG_FIN;
    }
    if ($frame->getRSV1()) {
        $flags |= SWOOLE_WEBSOCKET_FLAG_RSV1;
    }
    if ($frame->getRSV2()) {
        $flags |= SWOOLE_WEBSOCKET_FLAG_RSV2;
    }
    if ($frame->getRSV3()) {
        $flags |= SWOOLE_WEBSOCKET_FLAG_RSV3;
    }
    if ($frame->getMask()) {
        $flags |= SWOOLE_WEBSOCKET_FLAG_MASK;
    }

    return $flags;
}
