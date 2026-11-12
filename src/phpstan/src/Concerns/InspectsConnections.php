<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\PdoConnection;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Support\Facades\DB;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_all;
use function in_array;

trait InspectsConnections
{
    /**
     * Get the type of the connection or DB facade whose framework `transaction()` the call runs,
     * or null for any other call.
     */
    private function getTransactionReceiverType(StaticCall|MethodCall $node, Scope $scope): ?Type
    {
        if (! $node->name instanceof Identifier || $node->name->toLowerString() !== 'transaction') {
            return null;
        }

        if ($node instanceof StaticCall) {
            if (! $node->class instanceof Name || ! ReflectionHelper::isFacade($node->class, DB::class, 'DB')) {
                return null;
            }

            $type = new ObjectType($scope->resolveName($node->class));
        } else {
            $type = $scope->getType($node->var);

            if (! (new ObjectType(ConnectionInterface::class))->isSuperTypeOf($type)->yes()) {
                return null;
            }
        }

        return $this->runsConnectionMethod($type, 'transaction', $scope) ? $type : null;
    }

    /**
     * Get the key that identifies a literal `DB::connection('name')` call, or null for any other expression.
     */
    private function getConnectionName(Expr $expr, Scope $scope): ?string
    {
        if (! $this->isFacadeConnectionCall($expr, $scope)) {
            return null;
        }

        $name = $expr->getArg('name', 0)?->value;

        return $name instanceof String_ ? DB::class . '::connection:' . $name->value : null;
    }

    /**
     * Determine whether the expression is a `DB::connection()` call running the facade's own connection().
     *
     * @phpstan-assert-if-true StaticCall $expr
     */
    private function isFacadeConnectionCall(Expr $expr, Scope $scope): bool
    {
        return $expr instanceof StaticCall
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier
            && $expr->name->toLowerString() === 'connection'
            && ReflectionHelper::isFacade($expr->class, DB::class, 'DB')
            && $this->runsConnectionMethod(new ObjectType($scope->resolveName($expr->class)), 'connection', $scope);
    }

    /**
     * Determine whether every class of the type runs the framework's own declaration of the method:
     * the connection contract's, Connection's, PdoConnection's, the database manager's or the DB
     * facade's. An override's body is unknown.
     */
    private function runsConnectionMethod(Type $type, string $method, Scope $scope): bool
    {
        $classes = $type->getObjectClassReflections();

        return $classes !== [] && array_all(
            $classes,
            static fn (ClassReflection $class): bool => $class->hasMethod($method)
                && in_array($class->getMethod($method, $scope)->getDeclaringClass()->getName(), [ConnectionInterface::class, Connection::class, PdoConnection::class, DatabaseManager::class, DB::class], true),
        );
    }
}
