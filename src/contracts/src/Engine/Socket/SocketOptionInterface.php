<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Socket;

interface SocketOptionInterface
{
    public function getHost(): string;

    public function getPort(): int;

    /**
     * Connect timeout, seconds.
     */
    public function getTimeout(): ?float;

    /**
     * Get the Swoole protocol configuration.
     *
     * @return array{}|array{
     *     open_length_check: bool,
     *     package_max_length: int,
     *     package_length_type: string,
     *     package_length_offset: int,
     *     package_body_offset: int,
     * }
     */
    public function getProtocol(): array;
}
