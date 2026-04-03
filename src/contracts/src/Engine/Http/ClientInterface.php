<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface ClientInterface
{
    public function set(array $settings): bool;

    /**
     * @param string[][] $headers
     */
    public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface;
}
