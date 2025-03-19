<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Http;

use Hyperf\HttpMessage\Server\Response as Psr7Response;

class ServerResponse extends Psr7Response
{
    protected ?string $streamedContent = null;

    public function write($content): bool
    {
        $this->streamedContent .= $content;

        return true;
    }

    public function getStreamedContent(): ?string
    {
        return $this->streamedContent;
    }
}
