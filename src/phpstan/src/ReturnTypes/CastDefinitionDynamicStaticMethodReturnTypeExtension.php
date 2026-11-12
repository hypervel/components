<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsBinary;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsEncryptedCollection;
use Hypervel\Database\Eloquent\Casts\AsEnumArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEnumCollection;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\ArgumentsNormalizer;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function count;
use function in_array;
use function is_callable;
use function strcasecmp;

/**
 * Types the framework's cast definition helpers, such as AsCollection::of(), as the cast string they
 * build from constant arguments, so a model's casts() method can be read without running it.
 *
 * The helpers only build strings, so they are called with the arguments to keep the format exact.
 */
#[AutowiredService]
class CastDefinitionDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * The helpers, by the class that declares them.
     *
     * @var array<class-string<Castable>, list<string>>
     */
    private const array HELPERS = [
        AsArrayObject::class => ['nullable'],
        AsBinary::class => ['of', 'ulid', 'uuid'],
        AsCollection::class => ['nullable', 'of', 'using'],
        AsEncryptedCollection::class => ['of', 'using'],
        AsEnumArrayObject::class => ['of'],
        AsEnumCollection::class => ['of'],
    ];

    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return Castable::class;
    }

    /**
     * Determine whether the method is a cast definition helper.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), self::HELPERS[$methodReflection->getDeclaringClass()->getName()] ?? [], true);
    }

    /**
     * Get the cast string the helper builds.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $class = $methodReflection->getDeclaringClass();

        // A subclass could change what a helper calls through static::.
        if (
            ! $methodCall->class instanceof Name
            || $methodCall->class->isSpecialClassName()
            || strcasecmp($scope->resolveName($methodCall->class), $class->getName()) !== 0
        ) {
            return null;
        }

        $parametersAcceptor = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants());
        $arguments = ArgumentsNormalizer::reorderStaticCallArguments($parametersAcceptor, $methodCall)?->getArgs();
        $parameters = $parametersAcceptor->getParameters();

        if ($arguments === null || count($arguments) > count($parameters)) {
            return null;
        }

        $values = [];

        foreach ($parameters as $index => $parameter) {
            $argument = $arguments[$index] ?? null;

            if ($argument === null) {
                if (! $parameter->isOptional()) {
                    return null;
                }

                break;
            }

            $type = $scope->getType($argument->value);

            if ($argument->unpack || ! $parameter->getType()->isSuperTypeOf($type)->yes()) {
                return null;
            }

            $scalars = $type->getConstantScalarValues();

            if (count($scalars) === 1) {
                $values[] = $scalars[0];

                continue;
            }

            $mapping = $this->getCallableMapping($type);

            if ($mapping === null) {
                return null;
            }

            $values[] = $mapping;
        }

        // The cast string follows the helper's source, which analysis does not otherwise track.
        $scope->trackFileDependency((string) $class->getFileName());

        $helper = [$class->getName(), $methodReflection->getName()];

        return new ConstantStringType($helper(...$values));
    }

    /**
     * Get the [class, method] mapping a collection helper accepts, or null when the type is not one.
     *
     * The helpers turn a callable mapping into "class@method", but would cast any other array to a string.
     *
     * @return null|array{string, string}
     */
    private function getCallableMapping(Type $type): ?array
    {
        $arrays = $type->getConstantArrays();

        if (count($arrays) !== 1 || ! $type->isConstantArray()->yes() || count($arrays[0]->getValueTypes()) !== 2) {
            return null;
        }

        $class = $arrays[0]->getValueTypes()[0]->getConstantStrings();
        $method = $arrays[0]->getValueTypes()[1]->getConstantStrings();

        if (! $arrays[0]->isList()->yes() || count($class) !== 1 || count($method) !== 1) {
            return null;
        }

        $mapping = [$class[0]->getValue(), $method[0]->getValue()];

        return is_callable($mapping) ? $mapping : null;
    }
}
