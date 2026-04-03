<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface RequestInterface
{
    public function getPath(): string;

    public function getMethod(): string;

    public function getHeaders(): array;

    public function getBody(): string;

    public function isPipeline(): bool;
}
