<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\PHPStan\Types\Factory\ModelFactoryType;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\MethodTypeSpecifyingExtension;

use function in_array;

/**
 * Clear a factory's count after createMany(), createManyQuietly() or makeMany(), which clear it at runtime.
 *
 * PHPStan applies this to a call used as a statement or an assigned value. A @phpstan-self-out tag would
 * cover every call, but PHPStan stores a self-out type even for a receiver such as User::factory(), so later
 * calls to User::factory() would lose their count too.
 */
#[AutowiredService]
class FactoryCountResetTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    private TypeSpecifier $typeSpecifier;

    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Factory::class;
    }

    /**
     * Determine whether the call is a statement-level call of a base method that clears the count.
     */
    public function isMethodSupported(
        MethodReflection $methodReflection,
        MethodCall $node,
        TypeSpecifierContext $context,
    ): bool {
        return $context->null()
            && $methodReflection->getDeclaringClass()->getName() === Factory::class
            && in_array($methodReflection->getName(), ['createMany', 'createManyQuietly', 'makeMany'], true);
    }

    /**
     * Mark the receiving factory as making a single model.
     */
    public function specifyTypes(
        MethodReflection $methodReflection,
        MethodCall $node,
        Scope $scope,
        TypeSpecifierContext $context,
    ): SpecifiedTypes {
        $factoryType = $scope->getType($node->var);

        // A call such as User::factory() returns a new factory each time, so it must not keep a type.
        if ($node->var instanceof CallLike || ! $factoryType instanceof ModelFactoryType) {
            return new SpecifiedTypes;
        }

        return $this->typeSpecifier->create(
            $node->var,
            $factoryType->withSingleModel(TrinaryLogic::createYes()),
            TypeSpecifierContext::createTrue(),
            $scope,
        )->setAlwaysOverwriteTypes();
    }

    /**
     * Set the type specifier used to narrow the factory.
     */
    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }
}
