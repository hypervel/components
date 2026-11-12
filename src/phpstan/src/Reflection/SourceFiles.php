<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Reflection;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

use function array_fill_keys;
use function array_keys;
use function array_values;

/**
 * Finds the files that values read through reflection come from, for result cache dependencies.
 *
 * PHPStan analyzes the dependents of a class again only when its declarations change. Property defaults
 * and method bodies are not part of them, and a changed constant reaches only the files naming its class.
 *
 * @internal
 */
class SourceFiles
{
    /**
     * Get the files declaring a class and its parents below a base class, with the traits each uses.
     *
     * @return list<string>
     */
    public static function forClass(ClassReflection $classReflection, ?string $baseClass): array
    {
        $sources = [];

        foreach ([$classReflection, ...$classReflection->getParents()] as $ancestor) {
            if ($ancestor->getName() === $baseClass) {
                break;
            }

            $sources[] = $ancestor;

            // A class's getTraits(true) also lists every parent's traits, including the base class's, but a
            // trait's lists only the traits it uses.
            foreach ($ancestor->getTraits() as $trait) {
                $sources = [...$sources, $trait, ...array_values($trait->getTraits(true))];
            }
        }

        $files = [];

        foreach ($sources as $source) {
            if ($source->getFileName() !== null) {
                $files[$source->getFileName()] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * Get the files an instance's property defaults and method bodies come from: those declaring the class and
     * its parents below a base class, with their traits, and those of the constants the defaults read.
     *
     * @return list<string>
     */
    public static function forInstance(ReflectionProvider $reflectionProvider, ClassReflection $classReflection, ?string $baseClass): array
    {
        $files = array_fill_keys(self::forClass($classReflection, $baseClass), true);
        $constants = [];

        foreach ([$classReflection, ...$classReflection->getParents()] as $ancestor) {
            if ($ancestor->getName() === $baseClass) {
                break;
            }

            foreach ($ancestor->getNativeReflection()->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $ancestor->getName()) {
                    continue;
                }

                // An untyped property without a default has a null default but no expression.
                $default = $property->getBetterReflection()->getDefaultValueExpression();

                if ($default !== null) {
                    self::addExpressionFiles($reflectionProvider, $ancestor, $default, $files, $constants);
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Get the files a constant read through a class takes its value from: those declaring the class and its
     * parents up to the declaring class, as any of them can override it, and those of the constants it reads.
     *
     * @return list<string>
     */
    public static function forConstant(ReflectionProvider $reflectionProvider, ClassReflection $classReflection, string $name): array
    {
        $files = [];
        $constants = [];

        self::addConstantFiles($reflectionProvider, $classReflection, $name, $files, $constants);

        return array_keys($files);
    }

    /**
     * Get the files of the constants the arguments of a class's or trait's attribute read.
     *
     * @return list<string>
     */
    public static function forAttributeArguments(ReflectionProvider $reflectionProvider, ClassReflection $classReflection, string $attributeClass): array
    {
        $files = [];
        $constants = [];

        foreach ($classReflection->getNativeReflection()->getAttributes($attributeClass) as $attribute) {
            foreach ($attribute->getArgumentsExpressions() as $expression) {
                self::addExpressionFiles($reflectionProvider, $classReflection, $expression, $files, $constants);
            }
        }

        return array_keys($files);
    }

    /**
     * Add the files a constant read through a class takes its value from.
     *
     * @param array<string, true> $files
     * @param array<string, true> $constants the constants already visited
     */
    private static function addConstantFiles(ReflectionProvider $reflectionProvider, ClassReflection $classReflection, string $name, array &$files, array &$constants): void
    {
        // A constant can refer to itself in code that fails only once it runs.
        if (isset($constants[$classReflection->getName() . '::' . $name])) {
            return;
        }

        $constants[$classReflection->getName() . '::' . $name] = true;

        $constant = $classReflection->getConstant($name);
        $declaringClass = $constant->getDeclaringClass();

        $files += array_fill_keys(self::forClass($classReflection, $declaringClass->getParentClass()?->getName()), true);

        // An interface declaring the constant is not among the class's parents.
        if ($declaringClass->getFileName() !== null) {
            $files[$declaringClass->getFileName()] = true;
        }

        // A unit enum case has no value expression.
        if ($declaringClass->hasEnumCase($name) && ! $declaringClass->isBackedEnum()) {
            return;
        }

        self::addExpressionFiles($reflectionProvider, $declaringClass, $constant->getValueExpr(), $files, $constants);
    }

    /**
     * Add the files of the constants an initializer expression in a class reads.
     *
     * @param array<string, true> $files
     * @param array<string, true> $constants the constants already visited
     */
    private static function addExpressionFiles(ReflectionProvider $reflectionProvider, ClassReflection $scopeClass, Expr $expression, array &$files, array &$constants): void
    {
        foreach ((new NodeFinder)->findInstanceOf($expression, ClassConstFetch::class) as $fetch) {
            if (! $fetch->class instanceof Name || ! $fetch->name instanceof Identifier || $fetch->name->toLowerString() === 'class') {
                continue;
            }

            $className = match ($fetch->class->toLowerString()) {
                'self' => $scopeClass->getName(),
                'parent' => $scopeClass->getParentClass()?->getName(),
                default => $fetch->class->toString(),
            };

            if ($className === null || ! $reflectionProvider->hasClass($className)) {
                continue;
            }

            $class = $reflectionProvider->getClass($className);

            if ($class->hasConstant($fetch->name->toString())) {
                self::addConstantFiles($reflectionProvider, $class, $fetch->name->toString(), $files, $constants);
            }
        }
    }
}
