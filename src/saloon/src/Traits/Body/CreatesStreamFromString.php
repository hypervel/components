<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

trait CreatesStreamFromString
{
    /**
     * Convert the body repository into a stream.
     */
    public function toStream(): StreamInterface
    {
        return Utils::streamFor((string) $this);
    }
}
