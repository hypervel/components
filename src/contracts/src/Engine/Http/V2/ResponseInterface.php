<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface ResponseInterface
{
    public function getStreamId(): int;

    public function getStatusCode(): int;

    public function getHeaders(): array;

    public function getBody(): ?string;
}
