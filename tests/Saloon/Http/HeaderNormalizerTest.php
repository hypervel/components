<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Saloon\Exceptions\InvalidHeaderException;
use Hypervel\Saloon\Http\HeaderNormalizer;
use Hypervel\Tests\TestCase;

class HeaderNormalizerTest extends TestCase
{
    public function testHeaderNamesMustBeStrings(): void
    {
        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('HTTP header names must be strings.');

        HeaderNormalizer::normalize(['Content-Type', 'application/json']);
    }
}
