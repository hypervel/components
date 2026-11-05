<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Concerns\HasContainer;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Type\ClosureType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function is_object;
use function ltrim;
use function strcasecmp;

#[AutowiredService]
final class AppMakeHelper
{
    use HasContainer;

    /**
     * Resolve the type of the container entry requested by the call.
     *
     * Returns null when the call's declared return type already describes the
     * result, such as a generic class-string or a null abstract.
     */
    public function resolveTypeFromCall(FuncCall|MethodCall|StaticCall $call, Scope $scope): ?Type
    {
        $args = $call->getArgs();

        if (count($args) === 0) {
            return null;
        }

        // PHPStan reorders named arguments for extensions but leaves unpacked ones
        // as-is, and its own evaluation of an unpacked key would apply a facade alias.
        if ($args[0]->unpack) {
            return new MixedType;
        }

        $argType = $scope->getType($args[0]->value);

        $constantStrings = $argType->getConstantStrings();

        if (count($constantStrings) === 0) {
            return null;
        }

        $types = [];

        foreach ($constantStrings as $constantString) {
            $types[] = $this->resolveAbstractType($constantString->getValue());
        }

        return TypeCombinator::union(...$types);
    }

    /**
     * Resolve the type of the container entry for the given abstract.
     */
    public function resolveAbstractType(string $abstract): Type
    {
        $resolved = $this->resolve($abstract);

        if (is_object($resolved)) {
            return new ObjectType($resolved::class);
        }

        // Not every class-string has a reflection; PHPStan reports a missing class itself.
        $class = (new ConstantStringType($abstract))->getClassStringObjectType()->getObjectClassReflections()[0] ?? null;

        // A class that does not resolve to an object keeps its type. Upstream applies this to any class-string,
        // but facade aliases make keys such as 'session' class names, so only the class's own name qualifies.
        if ($class === null || strcasecmp(ltrim($abstract, '\\'), $class->getName()) !== 0) {
            return new MixedType;
        }

        return new ObjectType($class->getName());
    }

    /**
     * Resolve the type of the closure returned by a container factory() call.
     */
    public function resolveFactoryTypeFromCall(MethodCall|StaticCall $call, Scope $scope): ?Type
    {
        $type = $this->resolveTypeFromCall($call, $scope);

        return $type === null ? null : new ClosureType([], $type, false);
    }
}
