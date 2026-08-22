<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use Hypervel\Database\Eloquent\Attributes\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;

/**
 * Resolve model methods exposed as named Eloquent scopes.
 */
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

        foreach ($modelClass->getNativeReflection()->getMethods() as $method) {
            if ($method->isPrivate()) {
                continue;
            }

            $methodName = $method->getName();

            if (strncasecmp($methodName, 'scope', 5) === 0
                && strlen($methodName) > 5
                && ! ctype_lower($methodName[5])) {
                $legacyScopes[strtolower(substr($methodName, 5))] = $modelClass->getNativeMethod($methodName);
            }

            if ($method->getAttributes(Scope::class) !== []) {
                $attributedScopes[strtolower($methodName)] = $modelClass->getNativeMethod($methodName);
            }
        }

        return $this->methods[$cacheKey] = array_replace($legacyScopes, $attributedScopes);
    }
}
