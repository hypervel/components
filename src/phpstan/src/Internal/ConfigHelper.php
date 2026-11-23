<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Internal;

use Closure;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Support\ConfigParser;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Config;
use InvalidArgumentException;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
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
use PHPStan\TrinaryLogic;
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
use PHPStan\Type\VerbosityLevel;

use function array_all;
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
    private const array METHODS = [
        'get', 'string', 'integer', 'float', 'boolean', 'array', 'collection',
        'nullablestring', 'nullableinteger', 'nullablefloat', 'nullableboolean', 'nullablearray',
    ];

    /**
     * Create a new helper instance.
     */
    public function __construct(
        private ConfigParser $configParser,
        private RuntimeInputs $runtimeInputs,
        private WorkerStateHelper $workerStateHelper,
    ) {
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
        $lookup = $this->lookup($call, $scope);

        return $lookup === null ? null : $this->valueWithDefault($lookup, $call, $scope, false);
    }

    /**
     * Get the presence and the configured types of all possible literal keys.
     *
     * @param DependencyTracker&Scope $scope
     * @return null|array{TrinaryLogic, Type}
     */
    private function lookup(CallLike $call, Scope $scope): ?array
    {
        // PHPStan orders named arguments for extensions, but does not expand unpacked ones.
        if ($call->isFirstClassCallable() || array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)) {
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

        $presence = [];
        $types = [];

        foreach ($keyType->getConstantStrings() as $constantString) {
            $value = $this->configParser->getValue($constantString->getValue(), $scope);

            if ($value === null) {
                return null;
            }

            $presence[] = $value[0];

            if (! $value[0]->no()) {
                $types[] = $value[1];
            }
        }

        return [TrinaryLogic::extremeIdentity(...$presence), TypeCombinator::union(...$types)];
    }

    /**
     * Include a lookup's fallback only on a path that can return it.
     *
     * @param array{TrinaryLogic, Type} $lookup
     */
    private function valueWithDefault(array $lookup, CallLike $call, Scope $scope, bool $typed): Type
    {
        [$presence, $type] = $lookup;
        $default = $call->getArg('default', 1);

        if ($presence->yes() || ($typed && $default === null)) {
            return $type;
        }

        return TypeCombinator::union($type, $this->defaultType($default, $scope));
    }

    /**
     * Get the value returned by a default, evaluating only a Closure.
     */
    private function defaultType(?Arg $default, Scope $scope): Type
    {
        return $default === null ? new NullType : TypeTraverser::map(
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

        $lookup = $this->lookup($call, $scope);

        if ($lookup === null) {
            return null;
        }

        $valueType = $this->valueWithDefault($lookup, $call, $scope, $methodName !== 'get');

        if ($methodName === 'get') {
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
     * Describe a typed read that cannot succeed for any possible key.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeError(CallLike $call, Scope $scope, string $methodName): ?string
    {
        $lookup = $this->lookup($call, $scope);
        $key = $call->getArg('key', 0);

        if ($lookup === null || $key === null) {
            return null;
        }

        $getter = $this->getterName($methodName);
        $keyDescription = $scope->getType($key->value)->describe(VerbosityLevel::precise());

        if ($lookup[0]->no() && $call->getArg('default', 1) === null) {
            return sprintf('Config key %s is not defined, so %s() throws. Check the key and its configuration.', $keyDescription, $getter);
        }

        $value = $this->valueWithDefault($lookup, $call, $scope, true);

        if ($value instanceof NeverType || ! TypeCombinator::intersect($value, $this->getGetterType($methodName)) instanceof NeverType) {
            return null;
        }

        return sprintf(
            'Config key %s is not %s, so %s() throws. Correct the config value or use the getter that matches its type.',
            $keyDescription,
            $this->getterDescription($methodName),
            $getter,
        );
    }

    /**
     * Recommend a getter when every possible key is present and has one supported type.
     *
     * @param DependencyTracker&Scope $scope
     * @return null|array{string, string, string}
     */
    public function recommendedGetter(CallLike $call, Scope $scope): ?array
    {
        if (! ($call instanceof FuncCall && $this->workerStateHelper->isConfigFunction($call, $scope))
            && $this->getMethodName($call, $scope) !== 'get') {
            return null;
        }

        $key = $call->getArg('key', 0);

        if ($key === null || ! $scope->getType($key->value)->isConstantScalarValue()->yes()) {
            return null;
        }

        $this->runtimeInputs->track($scope);

        if ($this->runtimeInputs->isPackageApplication()) {
            return null;
        }

        $lookup = $this->lookup($call, $scope);

        if ($lookup === null || ! $lookup[0]->yes()) {
            return null;
        }

        $type = TypeCombinator::removeNull($lookup[1]);
        $method = match (true) {
            $type instanceof NeverType => null,
            $type->isString()->yes() => 'string',
            $type->isInteger()->yes() => 'integer',
            $type->isFloat()->yes() => 'float',
            $type->isBoolean()->yes() => 'boolean',
            $type->isArray()->yes() => 'array',
            default => null,
        };

        if ($method === null) {
            return null;
        }

        if (! $lookup[1]->isNull()->no()) {
            $method = 'nullable' . $method;
        }

        $default = $call->getArg('default', 1);

        if ($default !== null && ! TypeCombinator::addNull($this->getGetterType($method))->isSuperTypeOf($this->defaultType($default, $scope))->yes()) {
            return null;
        }

        return [
            $scope->getType($key->value)->describe(VerbosityLevel::precise()),
            $this->getterName($method),
            $this->getterDescription($method),
        ];
    }

    /**
     * Return the declared casing of a getter for diagnostic messages.
     */
    private function getterName(string $method): string
    {
        return str_starts_with($method, 'nullable') ? 'nullable' . ucfirst(substr($method, 8)) : $method;
    }

    /**
     * Describe the config values a getter accepts.
     */
    private function getterDescription(string $method): string
    {
        if (str_starts_with($method, 'nullable')) {
            return $this->getterDescription(substr($method, 8)) . ' or null';
        }

        return match ($method) {
            'string' => 'a string',
            'integer' => 'an integer',
            'float' => 'a float',
            'boolean' => 'a boolean',
            'array', 'collection' => 'an array',
            default => throw new InvalidArgumentException(sprintf('[%s] is not a typed config getter.', $method)),
        };
    }

    /**
     * Get the name of the standard repository or Config facade value method the call makes on the
     * application's configuration, or null when it calls another method, a custom implementation,
     * or a repository that may not be the application's.
     *
     * @param DependencyTracker&Scope $scope
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
            if (! $this->isApplicationRepository($call->var, $scope)) {
                return null;
            }

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
     * Determine whether the expression is the application's repository: config() without arguments,
     * or a constant resolution of it from the application container.
     *
     * The application's values say nothing about another repository, such as one built directly or
     * received as a parameter or property, which may hold other values.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function isApplicationRepository(Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof FuncCall && $this->workerStateHelper->isConfigFunction($expr, $scope)) {
            return $expr->getRawArgs() === [];
        }

        $abstracts = $expr instanceof CallLike ? $this->workerStateHelper->getApplicationResolvedAbstracts($expr, $scope) : null;

        if ($abstracts === null) {
            return false;
        }

        // The booted aliases decide which abstracts name the repository.
        $this->runtimeInputs->track($scope);

        // The analysis always runs on the framework container: the booted application or a test's own.
        /** @var Container $container */
        $container = $this->workerStateHelper->getContainer();

        return array_all($abstracts, static fn (string $abstract): bool => $container->getAlias($abstract) === 'config');
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
    private function getGetterType(string $methodName): Type
    {
        if (str_starts_with($methodName, 'nullable')) {
            return TypeCombinator::addNull($this->getGetterType(substr($methodName, 8)));
        }

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
