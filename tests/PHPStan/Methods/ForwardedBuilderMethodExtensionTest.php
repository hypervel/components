<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Methods;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\PHPStan\Methods\BuilderDependencies;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Methods\BuilderMacroResolver;
use Hypervel\PHPStan\Methods\ForwardedBuilderMethodExtension;
use Hypervel\PHPStan\Methods\MacroMethodsClassReflectionExtension;
use Hypervel\PHPStan\Methods\Macros\SoftDeletingMacros;
use Hypervel\PHPStan\Methods\ModelScopeMethodResolver;
use Hypervel\PHPStan\Reflection\AttributeClassNameResolver;
use Hypervel\Tests\PHPStan\TestCase;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Reflection\ReflectionProvider;

class ForwardedBuilderMethodExtensionTest extends TestCase
{
    public function testDoesNotReflectClassesDuringConstruction(): void
    {
        $reflectionProvider = $this->createMock(ReflectionProvider::class);
        $reflectionProvider->expects($this->never())->method('getClass');
        $dependencyTracker = $this->createStub(DeclarationDependencyTracker::class);
        $builderHelper = new BuilderHelper(
            $reflectionProvider,
            $dependencyTracker,
            $this->createStub(AttributeClassNameResolver::class),
        );

        new ForwardedBuilderMethodExtension(
            $reflectionProvider,
            new ModelScopeMethodResolver,
            new BuilderMacroResolver($reflectionProvider, [SoftDeletes::class => SoftDeletingMacros::class]),
            new BuilderDependencies($dependencyTracker, $builderHelper),
            $builderHelper,
            $this->createStub(MacroMethodsClassReflectionExtension::class),
        );
    }
}
