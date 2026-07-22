<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Exceptions;

use Hypervel\Foundation\Bootstrap\HandleExceptions as FoundationHandleExceptions;
use Hypervel\Testbench\Bootstrap\HandleExceptions;
use Hypervel\Testbench\Exceptions\DeprecatedException;
use Hypervel\Tests\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;

class DeprecatedExceptionTest extends TestCase
{
    #[Test]
    public function itCanBeConvertedToString(): void
    {
        $exception = new DeprecatedException('Error', 1, __FILE__, 3);

        $this->assertStringContainsString('Error' . PHP_EOL . PHP_EOL . __FILE__ . ':3', (string) $exception);
    }

    #[Test]
    public function itIgnoresDeprecationsWithoutAnApplication(): void
    {
        $application = new ReflectionProperty(FoundationHandleExceptions::class, 'app');
        $previousApplication = $application->getValue();
        $application->setValue(null, null);

        try {
            $method = new ReflectionMethod(HandleExceptions::class, 'shouldIgnoreDeprecationErrors');

            $this->assertTrue($method->invoke(new HandleExceptions));
        } finally {
            $application->setValue(null, $previousApplication);
        }
    }
}
