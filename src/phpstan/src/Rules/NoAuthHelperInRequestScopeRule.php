<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Auth\AuthManager;
use Hypervel\Http\Request;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ObjectType;

use function in_array;
use function sprintf;

/** @implements Rule<MethodCall> */
#[Conditional(attribute: new AutowiredService, if: '%checkAuthCallsWhenInRequestScope%')]
class NoAuthHelperInRequestScopeRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report auth() helper user checks made where the request is available.
     *
     * @param MethodCall $node
     *
     * @return list<IdentifierRuleError>
     *
     * @throws ShouldNotHappenException
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toLowerString();

        if (! in_array($methodName, ['check', 'user', 'guest'], true)) {
            return [];
        }

        if (! $node->var instanceof Node\Expr\FuncCall) {
            return [];
        }

        // Upstream matches the written name, which also matches a namespaced auth() function.
        if (! $node->var->name instanceof Name
            || $this->reflectionProvider->resolveFunctionName($node->var->name, $scope) !== 'auth'
        ) {
            return [];
        }

        $calledOnType = $scope->getType($node->var);

        if (! (new ObjectType(AuthManager::class))->isSuperTypeOf($calledOnType)->yes()) {
            return [];
        }

        $message = match ($methodName) {
            'check' => 'Do not use auth()->check() in a class that has access to the request. Use $%s->user() !== null instead.',
            'user' => 'Do not use auth()->user() in a class that has access to the request. Use $%s->user() instead.',
            'guest' => 'Do not use auth()->guest() in a class that has access to the request. Use $%s->user() === null instead.',
        };

        // Upstream offers an automatic fix for both cases below. Hypervel omits it:
        // Auth::resolveUsersUsing() or a request's own resolver changes what the
        // request returns, so the replacement is not proven equivalent. Upstream
        // also suggests $this->user() in static contexts, which have no $this.
        if (
            $scope->isInClass()
            && $scope->getClassReflection()->is(Request::class)
            && $scope->hasVariableType('this')->yes()
        ) {
            return [
                RuleErrorBuilder::message(sprintf($message, 'this'))
                    ->identifier('hypervel.noAuthHelperInRequestScope')
                    ->build(),
            ];
        }

        if (! $scope->hasVariableType('request')->yes()) {
            return [];
        }

        $requestType = $scope->getVariableType('request');

        if (! (new ObjectType(Request::class))->isSuperTypeOf($requestType)->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf($message, 'request'))
                ->identifier('hypervel.noAuthHelperInRequestScope')
                ->build(),
        ];
    }
}
