<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Reflection\AttributeClassNameResolver;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Types\Factory\ModelFactoryType;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function count;
use function is_string;

#[AutowiredService]
class ModelFactoryDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create the model factory return type extension.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private DeclarationDependencyTracker $declarationDependencyTracker,
        private AttributeClassNameResolver $attributeClassNames,
    ) {
    }

    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Model::class;
    }

    /**
     * Determine whether the static method is supported.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'factory';
    }

    /**
     * Get the model's factory, recording whether it makes one model or several.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $class = $methodCall->class;

        // Unlike upstream, which gives never, a class-string receiver names its model.
        $calledOnType = $class instanceof Name
           ? new ObjectType($scope->resolveName($class))
           : $scope->getType($class)->getObjectTypeOrClassStringObjectType();

        if ($calledOnType->getObjectClassReflections() === []) {
            return null;
        }

        // PHPStan reports a receiver union in which a model has no factory().
        foreach ($calledOnType->getObjectClassReflections() as $classReflection) {
            if (! $classReflection->hasMethod('factory')) {
                return null;
            }
        }

        if (count($methodCall->getArgs()) === 0) {
            $isSingleModel = TrinaryLogic::createYes();
        } elseif ($methodCall->getArgs()[0]->unpack) {
            // PHPStan does not expand an unpacked argument for extensions.
            $isSingleModel = TrinaryLogic::createMaybe();
        } else {
            $argType = $scope->getType($methodCall->getArgs()[0]->value);

            $numericType = TypeCombinator::union(
                new IntegerType,
                new FloatType,
                TypeCombinator::intersect(
                    new StringType,
                    new AccessoryNumericStringType,
                ),
            );

            $isSingleModel = $numericType->isSuperTypeOf($argType)->negate()->result;
        }

        return TypeCombinator::union(...array_map(
            function (ClassReflection $classReflection) use ($scope, $isSingleModel): Type {
                // Unlike upstream's error type, a model whose factory is not known, or whose own factory()
                // implementation decides it, keeps the declared return.
                $factoryReflection = $this->usesHasFactoryMethod($classReflection, 'factory')
                    ? $this->getFactoryReflection($classReflection, $scope)
                    : null;

                if ($factoryReflection === null) {
                    return $classReflection->getMethod('factory', $scope)->getVariants()[0]->getReturnType();
                }

                // A factory that declares its own templates keeps its generic type, whose create() and make()
                // declare a model or a collection, rather than a count that its template arguments would lose.
                if ($factoryReflection->isGeneric()) {
                    return $factoryReflection->getObjectType();
                }

                return new ModelFactoryType($factoryReflection->getName(), null, $factoryReflection, $isSingleModel);
            },
            $calledOnType->getObjectClassReflections(),
        ));
    }

    /**
     * Get the factory the model's factory() call returns.
     */
    private function getFactoryReflection(
        ClassReflection $modelReflection,
        Scope $scope,
    ): ?ClassReflection {
        // An override of newFactory() is the most specific declaration. When the model does not
        // override it, factory() returns the HasFactory TFactory generic.
        $factoryReflection = $this->getFactoryFromMethodReturnType($modelReflection, 'newFactory', $scope);

        if ($factoryReflection !== null) {
            return $factoryReflection;
        }

        // Hypervel: HasFactory's own newFactory() selects a static $factory default, then the model's own
        // #[UseFactory] attribute, before the naming convention. One it cannot resolve leaves the TFactory
        // declaration rather than a lower-priority choice.
        if ($this->usesHasFactoryMethod($modelReflection, 'newFactory')) {
            $selection = $this->getSelectedFactory($modelReflection);

            if ($selection !== false) {
                return $selection ?? $this->getFactoryFromMethodReturnType($modelReflection, 'factory', $scope);
            }
        }

        // TFactory is bound on the class that uses HasFactory. A subclass that inherits the trait
        // gets the factory of its own conventional name.
        if (! $this->isFactoryGenericBoundTo($modelReflection)) {
            return $this->getFactoryFromNamingConvention($modelReflection)
                ?? $this->getFactoryFromMethodReturnType($modelReflection, 'factory', $scope);
        }

        return $this->getFactoryFromMethodReturnType($modelReflection, 'factory', $scope)
            ?? $this->getFactoryFromNamingConvention($modelReflection);
    }

    /**
     * Determine whether the HasFactory TFactory generic applies to the model itself.
     */
    private function isFactoryGenericBoundTo(ClassReflection $modelReflection): bool
    {
        // Callers handle a factory() override and a static $factory default before this.
        return $modelReflection->getNativeReflection()->getMethod('factory')->getDeclaringClass()->getName() === $modelReflection->getName();
    }

    /**
     * Determine whether the model's method is HasFactory's own rather than an override.
     */
    private function usesHasFactoryMethod(ClassReflection $modelReflection, string $methodName): bool
    {
        return $modelReflection->hasNativeMethod($methodName)
            && $modelReflection->getNativeReflection()->getMethod($methodName)->getFileName() === $this->reflectionProvider->getClass(HasFactory::class)->getFileName();
    }

    /**
     * Get the factory a static $factory default or the model's own #[UseFactory] attribute selects.
     *
     * Returns false when neither selects one, and null when the selection cannot be resolved.
     */
    private function getSelectedFactory(ClassReflection $modelReflection): ClassReflection|false|null
    {
        if ($modelReflection->hasNativeProperty('factory') && $modelReflection->getNativeProperty('factory')->isStatic()) {
            // A property default is not part of the model's declarations.
            foreach (SourceFiles::forInstance($this->reflectionProvider, $modelReflection, Model::class) as $file) {
                $this->declarationDependencyTracker->trackFileDependency($modelReflection, $file);
            }

            $factoryClass = $modelReflection->getNativeReflection()->getDefaultProperties()['factory'] ?? null;

            // newFactory() only reads the property when it is set.
            if ($factoryClass !== null) {
                return is_string($factoryClass) ? $this->findFactory($factoryClass) : null;
            }
        }

        // PHP attributes are not inherited, and newFactory() reads only the model's own.
        $attributes = $modelReflection->getNativeReflection()->getAttributes(UseFactory::class);

        if ($attributes === []) {
            return false;
        }

        $arguments = $attributes[0]->getArgumentsExpressions();
        $factoryClass = $this->attributeClassNames->resolve($arguments[0] ?? $arguments['factoryClass'] ?? null, $modelReflection, $modelReflection);

        return $factoryClass !== null ? $this->findFactory($factoryClass) : null;
    }

    /**
     * Get the factory named by the factory name resolver.
     */
    private function getFactoryFromNamingConvention(ClassReflection $modelReflection): ?ClassReflection
    {
        return $this->findFactory(Factory::resolveFactoryName($modelReflection->getName()));
    }

    /**
     * Get the concrete factory class of the given name.
     */
    private function findFactory(string $className): ?ClassReflection
    {
        // Unlike upstream's class_exists(), this does not load the analyzed factory file.
        if (! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $factoryReflection = $this->reflectionProvider->getClass($className);

        if (! $factoryReflection->is(Factory::class) || $factoryReflection->isAbstract()) {
            return null;
        }

        // A class named without template arguments takes their defaults or bounds, as PHPStan resolves
        // a bare generic class name.
        return $factoryReflection->isGeneric() ? $factoryReflection->withTypes([]) : $factoryReflection;
    }

    /**
     * Get the concrete factory a model method declares it returns.
     */
    private function getFactoryFromMethodReturnType(
        ClassReflection $modelReflection,
        string $methodName,
        Scope $scope,
    ): ?ClassReflection {
        if (! $modelReflection->hasMethod($methodName)) {
            return null;
        }

        // Read through the model's type, so a declared static, as in GenericFactory<static>, is the called model.
        $factoryReflections = $modelReflection->getObjectType()
            ->getMethod($methodName, $scope)
            ->getVariants()[0]
            ->getReturnType()
            ->getObjectClassReflections();

        if (count($factoryReflections) !== 1) {
            return null;
        }

        foreach ($factoryReflections as $factoryReflection) {
            if (
                $factoryReflection->is(Factory::class)
                && ! $factoryReflection->isAbstract()
            ) {
                return $factoryReflection;
            }
        }

        return null;
    }
}
