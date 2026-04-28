<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Exceptions;

use Hypervel\Testbench\Exceptions\DeprecatedException;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DeprecatedExceptionTest extends TestCase
{
    #[Test]
    public function itCanBeConvertedToString()
    {
        $exception = new DeprecatedException('Error', 1, __FILE__, 3);

        $this->assertStringContainsString('Error' . PHP_EOL . PHP_EOL . __FILE__ . ':3', (string) $exception);
    }
}
