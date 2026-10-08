<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64AudioTest extends TestCase
{
    public function testBase64AudioRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 audio content cannot be empty.');

        new Base64Audio('');
    }
}
