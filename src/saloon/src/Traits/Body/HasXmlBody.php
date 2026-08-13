<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Body;

use Hypervel\Saloon\Http\PendingRequest;

trait HasXmlBody
{
    use HasStringBody;

    /**
     * Apply the XML body defaults.
     */
    public function bootHasXmlBody(PendingRequest $pendingRequest): void
    {
        if (! $pendingRequest->hasHeader('Content-Type')) {
            $pendingRequest->contentType('application/xml');
        }
    }
}
