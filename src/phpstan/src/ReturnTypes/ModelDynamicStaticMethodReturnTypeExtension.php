<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Reflection\ForwardedModelMethodReflection;
use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\PHPStan\Types\BuilderOf\BuilderOfType;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;

use function count;
use function in_array;

/** @internal */
#[AutowiredService]
class ModelDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create the model static method return type extension.
     */
    public function __construct(
        private BuilderHelper $builderHelper,
        private CollectionHelper $collectionHelper,
        private ReflectionProvider $reflectionProvider,
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
     * Determine whether the static call is a forwarded builder call or a native model method.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $this->isForwardedToBuilder($methodReflection) || $this->isNativeModelMethod($methodReflection->getName());
    }

    /**
     * Return the called model's builder or collection type.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $forwardedType = $this->isForwardedToBuilder($methodReflection)
            ? $this->getTypeFromForwardedBuilderCall($methodCall, $scope)
            : null;

        if ($forwardedType !== null) {
            return $forwardedType;
        }

        // Unlike upstream, a model's own declaration, such as HasBuilder's query(), is kept, and so is an
        // instance method's: parent::newQuery() runs on $this, so its late static model applies.
        if (! $this->isNativeModelMethod($methodReflection->getName())
            || ! $methodReflection->isStatic()
            || $methodReflection->getDeclaringClass()->getName() !== Model::class) {
            return null;
        }

        // Unlike upstream, the declaration decides: a callback's result, such as withoutEvents()', is not the called
        // model's builder or collection.
        $returnType = $methodReflection->getDeclaringClass()
            ->getMethod($methodReflection->getName(), $scope)
            ->getVariants()[0]
            ->getReturnType();

        if ((new ObjectType(EloquentBuilder::class))->isSuperTypeOf($returnType)->yes()) {
            $modelType = $methodCall->class instanceof Name
                ? $scope->resolveTypeByName($methodCall->class)
                : $scope->getType($methodCall->class)->getObjectTypeOrClassStringObjectType();

            if (! (new ObjectType(Model::class))->isSuperTypeOf($modelType)->yes()) {
                return null;
            }

            return new BuilderOfType($modelType instanceof ThisType ? $modelType->getStaticObjectType() : $modelType, $this->builderHelper);
        }

        if (in_array(Collection::class, $returnType->getReferencedClasses(), true)) {
            $modelType = $methodCall->class instanceof Name
                ? new ObjectType($scope->resolveName($methodCall->class))
                : $scope->getType($methodCall->class)->getObjectTypeOrClassStringObjectType();

            $types = [];

            foreach ($modelType->getObjectClassNames() as $modelName) {
                $types[] = $this->collectionHelper->determineCollectionClass($modelName, TypeCombinator::intersect($modelType, new ObjectType($modelName)));
            }

            if ($types !== []) {
                return TypeCombinator::union(...$types);
            }
        }

        return null;
    }

    /**
     * Determine whether Model::__callStatic() forwards the call to the Eloquent builder.
     */
    private function isForwardedToBuilder(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getPrototype() instanceof ForwardedModelMethodReflection;
    }

    /**
     * Determine whether the base model declares the method.
     */
    private function isNativeModelMethod(string $name): bool
    {
        return $this->reflectionProvider->getClass(Model::class)->hasNativeMethod($name);
    }

    /**
     * Resolve a forwarded call on a builder of the called model type, so `$class::findOrFail()`
     * on a `class-string<T>` keeps T.
     */
    private function getTypeFromForwardedBuilderCall(StaticCall $methodCall, Scope $scope): ?Type
    {
        if ($methodCall->class instanceof Name || $methodCall->isFirstClassCallable()) {
            return null;
        }

        $modelType = $scope->getType($methodCall->class)->getObjectTypeOrClassStringObjectType();

        // A concrete model class already resolves through ForwardedModelMethodExtension,
        // a template type is replaced by its bound there.
        if (! TypeUtils::containsTemplateType($modelType)) {
            return null;
        }

        $modelClassNames = $modelType->getObjectClassNames();

        if (count($modelClassNames) !== 1) {
            return null;
        }

        $builderType = $this->builderHelper->determineBuilderClass($modelClassNames[0], $modelType);

        if ($builderType === null) {
            return null;
        }

        return $scope->getType(new MethodCall(
            new TypeExpr($builderType),
            $methodCall->name,
            $methodCall->getArgs(),
        ));
    }
}
