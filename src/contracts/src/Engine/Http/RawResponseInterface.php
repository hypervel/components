<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface RawResponseInterface
{
    public function getStatusCode(): int;

    /**
     * @return string[][]
     */
    public function getHeaders(): array;

    public function getBody(): string;

    public function getVersion(): string;
}
