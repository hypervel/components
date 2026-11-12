<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Support\Facades\Auth;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function count;
use function strtolower;

#[AutowiredService]
class GuardExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AuthGuardHelper $authGuardHelper,
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return Guard::class;
    }

    /**
     * Determine whether the method is the guard's user().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'user';
    }

    /**
     * Get the user type of the guard the call was made on.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $declaredType = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())->getReturnType();

        // Unlike upstream, a guard declaring a narrower user() than the contract keeps its declaration.
        if (! $declaredType->isSuperTypeOf(TypeCombinator::addNull(new ObjectType(Authenticatable::class)))->yes()) {
            return null;
        }

        [$guardNames, $receiverClasses] = $this->getGuardSelection($scope, $methodCall);
        $authModels = $this->authGuardHelper->getUserModels($scope, $guardNames, $receiverClasses);

        if (count($authModels) === 0) {
            return null;
        }

        return TypeCombinator::addNull(
            TypeCombinator::union(...array_map(
                static fn (string $authModel): Type => new ObjectType($authModel),
                $authModels,
            )),
        );
    }

    /**
     * Get the names of the guards the call that returned the guard can select, and the classes receiving that call.
     *
     * The names are null for a guard that did not come from the auth factory, the Auth
     * facade or the auth() helper, which may be any configured guard.
     *
     * @param DependencyTracker&Scope $scope
     * @return array{?list<string>, list<string>}
     */
    private function getGuardSelection(Scope $scope, MethodCall $methodCall): array
    {
        $call = $methodCall->var;

        // Upstream reads the argument of any single-argument call, such as $this->guardFor('admins').
        if ($call instanceof MethodCall
            && $call->name instanceof Identifier
            && $call->name->toLowerString() === 'guard'
            && (new ObjectType(Factory::class))->isSuperTypeOf($scope->getType($call->var))->yes()
        ) {
            return [
                $this->authGuardHelper->getGuardNames($this->getArgument($call, 'name'), $scope, acceptsEnums: true),
                $scope->getType($call->var)->getObjectClassNames(),
            ];
        }

        if ($call instanceof StaticCall
            && $call->class instanceof Name
            && $call->name instanceof Identifier
            && $call->name->toLowerString() === 'guard'
            && (new ObjectType(Auth::class))->isSuperTypeOf(new ObjectType($scope->resolveName($call->class)))->yes()
        ) {
            return [$this->authGuardHelper->getGuardNames($this->getArgument($call, 'name'), $scope, acceptsEnums: true), []];
        }

        if ($call instanceof FuncCall
            && $call->name instanceof Name
            && strtolower((string) $this->reflectionProvider->resolveFunctionName($call->name, $scope)) === 'auth'
        ) {
            return [$this->authGuardHelper->getGuardNames($this->getArgument($call, 'guard'), $scope, acceptsEnums: false), []];
        }

        return [null, []];
    }

    /**
     * Get the argument a call passes for its first parameter.
     */
    private function getArgument(CallLike $call, string $parameter): ?Arg
    {
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->name === null ? $position === 0 : $arg->name->toString() === $parameter) {
                return $arg;
            }
        }

        return null;
    }
}
