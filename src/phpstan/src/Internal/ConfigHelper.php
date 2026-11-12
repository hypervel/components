<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Internal;

use Closure;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Config\Repository;
use Hypervel\PHPStan\Support\ConfigParser;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Config;
use InvalidArgumentException;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FloatType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;

use function array_any;
use function in_array;
use function sprintf;
use function strtolower;

/** @internal */
#[AutowiredService]
class ConfigHelper
{
    /**
     * The repository and Config facade methods whose return types come from config values.
     */
    private const array METHODS = ['get', 'string', 'integer', 'float', 'boolean', 'array', 'collection'];

    /**
     * Create a new helper instance.
     */
    public function __construct(private ConfigParser $configParser)
    {
    }

    /**
     * Get the type a config lookup's value has, or null when the declared type applies.
     *
     * A present key gives its value, even when null, so a default cannot hide an
     * incompatible value. An absent key gives the default, or null without one. Only a
     * Closure default is called, as value() does.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getValueType(CallLike $call, Scope $scope): ?Type
    {
        // PHPStan orders named arguments for extensions, but does not expand unpacked ones.
        if (array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)) {
            return null;
        }

        $key = $call->getArg('key', 0);

        if ($key === null) {
            return null;
        }

        $keyType = $scope->getType($key->value);

        if (! $keyType->isString()->yes() || ! $keyType->isConstantScalarValue()->yes()) {
            return null;
        }

        $default = $call->getArg('default', 1);
        $defaultType = $default === null ? new NullType : TypeTraverser::map(
            $scope->getType($default->value),
            static function (Type $type, callable $traverse) use ($scope): Type {
                // value() calls a default that is itself a Closure, not one the default holds.
                if ($type instanceof UnionType) {
                    return $traverse($type);
                }

                return (new ObjectType(Closure::class))->isSuperTypeOf($type)->yes()
                    ? $type->getCallableParametersAcceptors($scope)[0]->getReturnType()
                    : $type;
            },
        )->generalize(GeneralizePrecision::lessSpecific());

        $types = [];

        foreach ($keyType->getConstantStrings() as $constantString) {
            $value = $this->configParser->getValue($constantString->getValue(), $scope);

            if ($value === null) {
                return null;
            }

            [$presence, $type] = $value;

            if (! $presence->no()) {
                $types[] = $type;
            }

            if (! $presence->yes()) {
                $types[] = $defaultType;
            }
        }

        return TypeCombinator::union(...$types);
    }

    /**
     * Get the type a repository or Config facade method returns, or null when the
     * declared type applies.
     *
     * The typed getters throw for a value of another type, so a value that never has
     * the getter's type gives an error type, which ConfigTypeRule and ConfigCollectionRule
     * report.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getMethodReturnType(CallLike $call, Scope $scope): ?Type
    {
        $methodName = $this->getMethodName($call, $scope);

        if ($methodName === null) {
            return null;
        }

        $valueType = $this->getValueType($call, $scope);

        if ($valueType === null || $methodName === 'get') {
            return $valueType;
        }

        $type = TypeCombinator::intersect($valueType, $this->getGetterType($methodName));

        if ($type instanceof NeverType) {
            return new ErrorType;
        }

        if ($methodName === 'collection') {
            return new GenericObjectType(Collection::class, [$type->getIterableKeyType(), $type->getIterableValueType()]);
        }

        return $type;
    }

    /**
     * Get the name of the standard repository or Config facade value method the call makes,
     * or null when it calls another method or a custom implementation.
     */
    public function getMethodName(CallLike $call, Scope $scope): ?string
    {
        if ((! $call instanceof MethodCall && ! $call instanceof StaticCall) || ! $call->name instanceof Identifier) {
            return null;
        }

        $methodName = $call->name->toLowerString();

        if (! in_array($methodName, self::METHODS, true)) {
            return null;
        }

        if ($call instanceof MethodCall) {
            [$calledOnType, $receiverClass] = [$scope->getType($call->var), Repository::class];
        } elseif ($call->class instanceof Name) {
            [$calledOnType, $receiverClass] = [new ObjectType($scope->resolveName($call->class)), Config::class];
        } else {
            return null;
        }

        if (! (new ObjectType($receiverClass))->isSuperTypeOf($calledOnType)->yes()) {
            return null;
        }

        // The repository's typed getters read through get(), and collection() through array(),
        // so a receiver overriding either changes what they return. Facade calls forward to the
        // bound repository, so a facade subclass's own methods do not change them.
        $methodNames = match (true) {
            $call instanceof StaticCall, $methodName === 'get' => [$methodName],
            $methodName === 'collection' => [$methodName, 'array', 'get'],
            default => [$methodName, 'get'],
        };

        foreach ($methodNames as $name) {
            $method = $scope->getMethodReflection($calledOnType, $name);

            if ($method === null || ! $this->isConfigMethod($method)) {
                return null;
            }
        }

        return $methodName;
    }

    /**
     * Determine whether the method is one of the standard repository's, its contract's or the
     * Config facade's value methods. A custom implementation keeps its declared return type.
     */
    public function isConfigMethod(MethodReflection $method): bool
    {
        return in_array(strtolower($method->getName()), self::METHODS, true)
            && in_array($method->getDeclaringClass()->getName(), [Repository::class, ConfigRepository::class, Config::class], true);
    }

    /**
     * Get the type a typed getter requires its value to have.
     */
    public function getGetterType(string $methodName): Type
    {
        return match ($methodName) {
            'string' => new StringType,
            'integer' => new IntegerType,
            'float' => new FloatType,
            'boolean' => new BooleanType,
            'array', 'collection' => new ArrayType(new MixedType, new MixedType),
            default => throw new InvalidArgumentException(sprintf('[%s] is not a typed config getter.', $methodName)),
        };
    }
}
