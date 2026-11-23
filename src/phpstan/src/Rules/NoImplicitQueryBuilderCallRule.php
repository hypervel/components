<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Methods\ForwardedModelMethodExtension;
use Hypervel\PHPStan\Methods\NamedScopeMethodExtension;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope as AnalyserScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\MultipleNodeTypesRule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

use function count;
use function sprintf;

/**
 * Report calls a model forwards to its query builder, which an explicit query() or newQuery() call makes clear.
 *
 * @implements MultipleNodeTypesRule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%noImplicitQueryBuilderCall%')]
class NoImplicitQueryBuilderCallRule implements MultipleNodeTypesRule
{
    /**
     * The model methods that forward an instance call to newQuery().
     */
    private const array FORWARDING_METHODS = ['__call', 'forwardCallTo'];

    /**
     * The model methods that forward a static call to a new instance's newQuery(), which query() also runs.
     */
    private const array STATIC_FORWARDING_METHODS = ['__callStatic', '__call', 'forwardCallTo', 'query'];

    /**
     * Create a new implicit query builder call rule instance.
     */
    public function __construct(
        private ForwardedModelMethodExtension $forwardedModelMethodExtension,
        private NamedScopeMethodExtension $namedScopeMethodExtension,
        private ModelRuleHelper $modelRuleHelper,
    ) {
    }

    /**
     * Get the node types the rule checks.
     */
    public function getNodeTypes(): array
    {
        return [StaticCall::class, MethodCall::class, NullsafeMethodCall::class];
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report a model call that is forwarded to the query builder.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, AnalyserScope $scope): array
    {
        if ((! $node instanceof StaticCall && ! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall) || ! $node->name instanceof Identifier) {
            return [];
        }

        if ($node->getAttribute('virtualNullsafeMethodCall', false)) {
            return [];
        }

        $receiver = $node instanceof StaticCall ? $node->class : $node->var;
        $type = $receiver instanceof Name ? $scope->resolveTypeByName($receiver) : $scope->getType($receiver);

        if ($node instanceof StaticCall) {
            $type = $type->getObjectTypeOrClassStringObjectType();
        } elseif ($node instanceof NullsafeMethodCall) {
            $type = TypeCombinator::removeNull($type);
        }

        if (! (new ObjectType(Model::class))->isSuperTypeOf($type)->yes()) {
            return [];
        }

        $reflections = $type->getObjectClassReflections();

        if (count($reflections) !== 1) {
            return [];
        }

        $model = $reflections[0];
        $methodName = $node->name->toString();

        if ($model->hasNativeMethod($methodName)) {
            $method = $model->getNativeMethod($methodName);

            if ($scope->canCallMethod($method)) {
                return [];
            }

            $isScope = false;

            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === Scope::class) {
                    $isScope = true;
                    break;
                }
            }

            if (! $isScope) {
                return [];
            }
        }

        // Unlike upstream's single extension, named scopes are described apart from the other forwarded builder methods.
        if (! $this->forwardedModelMethodExtension->hasMethod($model, $methodName)
            && ! $this->namedScopeMethodExtension->hasMethod($model, $methodName)) {
            return [];
        }

        // Hypervel: the explicit query is the same query only when the model forwards the call as Model does.
        if (! $this->modelRuleHelper->usesModelMethods($model, $node instanceof StaticCall ? self::STATIC_FORWARDING_METHODS : self::FORWARDING_METHODS)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call to %smethod %s::%s() is forwarded to the query builder.',
                $node instanceof StaticCall ? 'static ' : '',
                $model->getDisplayName(),
                $methodName,
            ))
                ->tip(sprintf(
                    'Use %s->%s() instead.',
                    $node instanceof StaticCall ? $model->getDisplayName() . '::query()' : 'newQuery()',
                    $methodName,
                ))
                ->identifier('hypervel.noImplicitQueryBuilderCall')
                ->fixNode($node, static function (StaticCall|MethodCall|NullsafeMethodCall $node): MethodCall {
                    $builder = match (true) {
                        $node instanceof StaticCall => new StaticCall($node->class, 'query'),
                        $node instanceof NullsafeMethodCall => new NullsafeMethodCall($node->var, 'newQuery'),
                        default => new MethodCall($node->var, 'newQuery'),
                    };

                    return new MethodCall($builder, $node->name, $node->args);
                })
                ->build(),
        ];
    }
}
