<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\PHPStan;

use Hypervel\Database\PHPStan\ForwardedBuilderMethodExtension;
use Hypervel\Database\PHPStan\ModelScopeMethodResolver;
use Hypervel\Tests\TestCase;
use PHPStan\Reflection\ReflectionProvider;

class ForwardedBuilderMethodExtensionTest extends TestCase
{
    public function testDoesNotReflectClassesDuringConstruction(): void
    {
        $reflectionProvider = $this->createMock(ReflectionProvider::class);
        $reflectionProvider->expects($this->never())->method('getClass');

        new ForwardedBuilderMethodExtension($reflectionProvider, new ModelScopeMethodResolver);
    }
}
