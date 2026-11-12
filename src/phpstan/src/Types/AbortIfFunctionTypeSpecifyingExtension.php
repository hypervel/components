<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\FunctionTypeSpecifyingExtension;

use function count;
use function str_starts_with;

class AbortIfFunctionTypeSpecifyingExtension implements FunctionTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    private TypeSpecifier $typeSpecifier;

    private string $methodName;

    /**
     * Create an extension for the "_if" or negated "_unless" form of the given helper.
     */
    public function __construct(private bool $negate, string $methodName)
    {
        $this->methodName = $methodName . '_' . ($negate === false ? 'if' : 'unless');
    }

    /**
     * Determine whether the call is a statement-level call of the helper.
     */
    public function isFunctionSupported(
        FunctionReflection $functionReflection,
        FuncCall $node,
        TypeSpecifierContext $context,
    ): bool {
        return $functionReflection->getName() === $this->methodName && $context->null();
    }

    /**
     * Narrow the condition to the outcome that lets the call return.
     */
    public function specifyTypes(
        FunctionReflection $functionReflection,
        FuncCall $node,
        Scope $scope,
        TypeSpecifierContext $context,
    ): SpecifiedTypes {
        $args = $node->getArgs();

        // Hypervel: an unpacked first argument is the whole argument array, not the condition.
        if ($args === [] || $args[0]->unpack) {
            return new SpecifiedTypes;
        }

        if (! str_starts_with($this->methodName, 'throw') && count($node->args) < 2) {
            return new SpecifiedTypes;
        }

        $context = $this->negate === false ? TypeSpecifierContext::createFalsey() : TypeSpecifierContext::createTruthy();

        return $this->typeSpecifier->specifyTypesInCondition($scope, $args[0]->value, $context);
    }

    /**
     * Set the type specifier used to narrow the condition.
     */
    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }
}
