<?php

declare(strict_types=1);

namespace Hypervel\Mail\Contracts;

use Hypervel\Mail\Attachment;

interface Attachable
{
    /**
     * Get an attachment instance for this entity.
     */
    public function toMailAttachment(): Attachment;
}
