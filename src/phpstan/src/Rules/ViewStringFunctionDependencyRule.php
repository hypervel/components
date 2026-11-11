<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\PHPStan\Types\ViewStringType;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function strtolower;

/**
 * Declares that a call to a function whose signature uses a view-string depends on which views exist.
 *
 * ViewStringTypeNodeResolverExtension declares it on the class of a member, but a function has
 * no class, so each call declares it.
 *
 * @implements Rule<FuncCall>
 */
#[AutowiredService]
class ViewStringFunctionDependencyRule implements Rule
{
    /**
     * Whether each function's signature uses a view-string, by lowercased name.
     *
     * @var array<string, bool>
     */
    private array $functions = [];

    /**
     * Create a new view-string function dependency rule.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * Declare the dependency of a call to a function whose signature uses a view-string.
     *
     * @param FuncCall $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name || ! $this->reflectionProvider->hasFunction($node->name, $scope)) {
            return [];
        }

        $function = $this->reflectionProvider->getFunction($node->name, $scope);

        if ($this->functions[strtolower($function->getName())] ??= $this->usesViewString($function)) {
            $this->runtimeInputs->trackViews($scope);
        }

        return [];
    }

    /**
     * Determine whether a function's parameter, return or assertion types use a view-string.
     */
    private function usesViewString(FunctionReflection $function): bool
    {
        foreach ($function->getVariants() as $variant) {
            if (ViewStringType::occursIn($variant->getReturnType())) {
                return true;
            }

            foreach ($variant->getParameters() as $parameter) {
                if (ViewStringType::occursIn($parameter->getType())) {
                    return true;
                }
            }
        }

        foreach ($function->getAsserts()->getAll() as $assert) {
            if (ViewStringType::occursIn($assert->getType())) {
                return true;
            }
        }

        return false;
    }
}
