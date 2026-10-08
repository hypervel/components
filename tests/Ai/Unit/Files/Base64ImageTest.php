<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Base64Image;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64ImageTest extends TestCase
{
    public function testBase64ImageRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 image content cannot be empty.');

        new Base64Image('');
    }
}
