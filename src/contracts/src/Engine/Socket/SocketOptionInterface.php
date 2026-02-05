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
     * @return [
     *     'open_length_check' => true,
     *     'package_max_length' => 1024 * 1024 * 2,
     *     'package_length_type' => 'N',
     *     'package_length_offset' => 0,
     *     'package_body_offset' => 4,
     * ]
     */
    public function getProtocol(): array;
}
