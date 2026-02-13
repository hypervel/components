<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\WebSocket;

interface ResponseInterface
{
    public function push(FrameInterface $frame): bool;

    /**
     * Init fd by frame or request and so on,
     * Must be used in swoole process mode.
     */
    public function init(mixed $frame): static;

    public function getFd(): int;

    public function close(): bool;
}
