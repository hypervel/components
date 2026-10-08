<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64DocumentTest extends TestCase
{
    public function testBase64DocumentRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 document content cannot be empty.');

        new Base64Document('');
    }
}
