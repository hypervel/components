<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage\Upload;

use Hypervel\HttpMessage\Upload\UploadedFile;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class UploadedFileTest extends TestCase
{

    public function testGetSize()
    {
        $file = new UploadedFile('', 10, 0);

        $this->assertSame(10, $file->getSize());
    }
}
