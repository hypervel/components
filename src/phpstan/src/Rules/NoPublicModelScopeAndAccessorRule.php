<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope as AnalyserScope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

use function sprintf;
use function strcasecmp;

/**
 * Unlike upstream, scopes and accessors are recognized as the runtime finds them: a legacy scope by its name and a
 * first parameter that takes the builder, and an accessor by its native Attribute return type. Only a private
 * method is made protected automatically, because a public one can have callers in other files.
 *
 * @implements Rule<InClassMethodNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkModelMethodVisibility%')]
class NoPublicModelScopeAndAccessorRule implements Rule
{
    /**
     * Create a new no public model scope and accessor rule instance.
     */
    public function __construct(
        private ModelRuleHelper $modelRuleHelper,
        #[AutowiredParameter(ref: '%noLegacyModelScopes%')]
        private bool $noLegacyModelScopes,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * Report model scopes and accessors that are not protected.
     *
     * @param InClassMethodNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, AnalyserScope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $methodName = $node->getMethodReflection()->getName();

        if (! $classReflection->is(Model::class)) {
            return [];
        }

        // Hypervel: an interface's method must stay public, like an inherited one keeps its visibility.
        if ($this->modelRuleHelper->declaresInheritedMethod($classReflection, $methodName)) {
            return [];
        }

        // Hypervel: NoLegacyModelScopesRule reports a legacy scope it can convert, with the protected #[Scope] method to declare.
        if ($this->isScopeMethod($node)
            && ! ($this->noLegacyModelScopes && $this->modelRuleHelper->attributedScopeName($classReflection, $node->getMethodReflection()) !== null)) {
            if (! $node->getOriginalNode()->isProtected()) {
                $error = RuleErrorBuilder::message(
                    sprintf(
                        "Local query scope method '%s' should be declared as protected.",
                        $methodName,
                    ),
                )
                    ->identifier('hypervel.noPublicModelScopeMethod')
                    ->line($node->getStartLine())
                    ->file($scope->getFile());

                return [
                    ($node->getOriginalNode()->isPrivate() ? $error->fixNode($node->getOriginalNode(), self::makeProtected(...)) : $error)->build(),
                ];
            }
        }

        if ($this->isAccessorMethod($node)) {
            if (! $node->getOriginalNode()->isProtected()) {
                $error = RuleErrorBuilder::message(
                    sprintf(
                        "Model accessor method '%s' should be declared as protected.",
                        $methodName,
                    ),
                )
                    ->identifier('hypervel.noPublicModelAccessorMethod')
                    ->line($node->getStartLine())
                    ->file($scope->getFile());

                return [
                    ($node->getOriginalNode()->isPrivate() ? $error->fixNode($node->getOriginalNode(), self::makeProtected(...)) : $error)->build(),
                ];
            }
        }

        return [];
    }

    /**
     * Determine whether the method is a local scope, as Model::hasNamedScope() finds one.
     */
    private function isScopeMethod(InClassMethodNode $method): bool
    {
        if ($this->modelRuleHelper->isLegacyScopeMethod($method->getMethodReflection())) {
            return true;
        }

        foreach ($method->getOriginalNode()->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (strcasecmp($attr->name->toString(), Scope::class) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether the method is an accessor, whose native return type names Attribute, as
     * HasAttributes::hasAttributeMutator() reads it.
     */
    private function isAccessorMethod(InClassMethodNode $method): bool
    {
        $returnType = TypeCombinator::removeNull($method->getMethodReflection()->getVariants()[0]->getNativeReturnType());

        // A union or intersection is not a ReflectionNamedType, so it is not an accessor.
        return $returnType->isObject()->yes() && $returnType->getObjectClassNames() === [Attribute::class];
    }

    /**
     * Make a private method protected.
     */
    private static function makeProtected(ClassMethod $node): ClassMethod
    {
        $node->flags &= ~Modifiers::PRIVATE;
        $node->flags |= Modifiers::PROTECTED;

        return $node;
    }
}
