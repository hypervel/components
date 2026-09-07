<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\PHPStan;

use Hypervel\Database\PHPStan\ForwardedFluentMethodExtension;
use Hypervel\Tests\TestCase;
use PHPStan\Reflection\ReflectionProvider;

class ForwardedFluentMethodExtensionTest extends TestCase
{
    public function testDoesNotReflectClassesDuringConstruction(): void
    {
        $reflectionProvider = $this->createMock(ReflectionProvider::class);
        $reflectionProvider->expects($this->never())->method('getClass');

        new ForwardedFluentMethodExtension($reflectionProvider);
    }
}
