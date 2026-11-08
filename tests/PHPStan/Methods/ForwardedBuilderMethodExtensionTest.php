<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Methods;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\PHPStan\Methods\BuilderDependencies;
use Hypervel\PHPStan\Methods\BuilderMacroResolver;
use Hypervel\PHPStan\Methods\ForwardedBuilderMethodExtension;
use Hypervel\PHPStan\Methods\Macros\SoftDeletingMacros;
use Hypervel\PHPStan\Methods\ModelScopeMethodResolver;
use Hypervel\Tests\PHPStan\TestCase;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Reflection\ReflectionProvider;

class ForwardedBuilderMethodExtensionTest extends TestCase
{
    public function testDoesNotReflectClassesDuringConstruction(): void
    {
        $reflectionProvider = $this->createMock(ReflectionProvider::class);
        $reflectionProvider->expects($this->never())->method('getClass');

        new ForwardedBuilderMethodExtension(
            $reflectionProvider,
            new ModelScopeMethodResolver,
            new BuilderMacroResolver($reflectionProvider, [SoftDeletes::class => SoftDeletingMacros::class]),
            new BuilderDependencies($this->createStub(DeclarationDependencyTracker::class)),
        );
    }
}
