<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Model;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

/**
 * Resolve model methods exposed as named Eloquent scopes.
 */
#[AutowiredService]
class ModelScopeMethodResolver
{
    /** @var array<string, array<string, MethodReflection>> */
    private array $methods = [];

    /**
     * Resolve a model scope by its exposed name.
     */
    public function resolve(ClassReflection $modelClass, string $scope): ?MethodReflection
    {
        return $this->methods($modelClass)[strtolower($scope)] ?? null;
    }

    /**
     * Resolve a scope for a model type, and the class declaring it.
     *
     * An intersection such as Model&HasTimeline can declare the scope through an interface. A union is declined,
     * as its members can resolve the name differently.
     *
     * @return null|array{ClassReflection, MethodReflection}
     */
    public function resolveForType(Type $modelType, string $scope): ?array
    {
        $types = TypeUtils::flattenTypes($modelType);

        if (count($types) !== 1) {
            return null;
        }

        $classes = $types[0]->getObjectClassReflections();
        $modelClasses = array_filter($classes, static fn (ClassReflection $class): bool => $class->is(Model::class));

        // The model's own scopes win, as Eloquent checks the model's attributed scopes first.
        foreach ([...$modelClasses, ...array_diff_key($classes, $modelClasses)] as $class) {
            $method = $this->resolve($class, $scope);

            if ($method !== null) {
                return [$class, $method];
            }
        }

        return null;
    }

    /**
     * Determine whether a method's declared name makes it a legacy scope, as Model::isLegacyScopeMethod() reads it.
     */
    public static function isLegacyScopeName(string $methodName): bool
    {
        return strncasecmp($methodName, 'scope', 5) === 0
            && strlen($methodName) > 5
            && ! ctype_lower($methodName[5]);
    }

    /**
     * Return the named scopes exposed by a model.
     *
     * @return array<string, MethodReflection>
     */
    private function methods(ClassReflection $modelClass): array
    {
        $cacheKey = $modelClass->getCacheKey();

        if (isset($this->methods[$cacheKey])) {
            return $this->methods[$cacheKey];
        }

        $legacyScopes = [];
        $attributedScopes = [];

        // Unlike upstream, an @method scopeX tag creates no scope: Model::hasNamedScope() needs a real method.
        foreach ($modelClass->getNativeReflection()->getMethods() as $method) {
            if ($method->isPrivate()) {
                continue;
            }

            $methodName = $method->getName();

            if (self::isLegacyScopeName($methodName)) {
                $legacyScopes[strtolower(substr($methodName, 5))] = $modelClass->getNativeMethod($methodName);
            }

            // An implementation does not inherit an interface method's attributes.
            if (! $modelClass->isInterface() && $method->getAttributes(Scope::class) !== []) {
                $attributedScopes[strtolower($methodName)] = $modelClass->getNativeMethod($methodName);
            }
        }

        return $this->methods[$cacheKey] = array_replace($legacyScopes, $attributedScopes);
    }
}
