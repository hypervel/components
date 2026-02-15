<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Psr\Http\Message\ResponseInterface;

interface ResponsePlusInterface extends MessagePlusInterface, ResponseInterface
{
    public function getStatusCode(): int;

    public function getReasonPhrase(): string;

    public function setStatus(int $code, string $reasonPhrase = ''): static;

    public function withStatus(int $code, string $reasonPhrase = ''): static;
}
