<?php

declare(strict_types=1);

namespace Hypervel\Testing\HttpMessage\Upload;

use Hypervel\HttpMessage\Upload\UploadedFile as BaseUploadedFile;

class UploadedFile extends BaseUploadedFile
{
    /**
     * Always return true in test contexts since files aren't HTTP-uploaded.
     */
    public function isValid(): bool
    {
        return true;
    }
}
