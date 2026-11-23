<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Support\Str;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_key_exists;

#[AutowiredService]
class ModelFactoryMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /**
     * Create the model factory methods extension.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Determine whether the factory has a dynamic relationship or trashed method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (! $classReflection->is(Factory::class)) {
            return false;
        }

        // Unlike upstream, which reads only the class and its direct parent, a factory extending another
        // factory reads TModel too.
        $modelType = $classReflection->getAncestorWithClassName(Factory::class)?->getActiveTemplateTypeMap()->getType('TModel');

        if ($modelType === null) {
            return false;
        }

        if ($modelType->getObjectClassReflections() !== []) {
            $modelReflection = $modelType->getObjectClassReflections()[0];
        } else {
            $modelReflection = $this->reflectionProvider->getClass(Model::class);
        }

        if ($methodName === 'trashed' && array_key_exists(SoftDeletes::class, $modelReflection->getTraits(true))) {
            return true;
        }

        if (! Str::startsWith($methodName, ['for', 'has'])) {
            return false;
        }

        $relationship = Str::camel(Str::substr($methodName, 3));

        if (! $modelType->hasMethod($relationship)->yes()) {
            return false;
        }

        // Unlike upstream, the method must be able to return a relation, whose related model __call() reads.
        $returnType = $modelType->getMethod($relationship, new OutOfClassScope)->getVariants()[0]->getReturnType();

        return ! (new ObjectType(Relation::class))->isSuperTypeOf($returnType)->no();
    }

    /**
     * Get the dynamic factory method.
     */
    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        return new class($classReflection, $methodName) implements MethodReflection {
            /**
             * Create the dynamic factory method reflection.
             */
            public function __construct(private ClassReflection $classReflection, private string $methodName)
            {
            }

            /**
             * Get the class declaring the method.
             */
            public function getDeclaringClass(): ClassReflection
            {
                return $this->classReflection;
            }

            /**
             * Determine whether the method is static.
             */
            public function isStatic(): bool
            {
                return false;
            }

            /**
             * Determine whether the method is private.
             */
            public function isPrivate(): bool
            {
                return false;
            }

            /**
             * Determine whether the method is public.
             */
            public function isPublic(): bool
            {
                return true;
            }

            /**
             * Get the method's doc comment.
             */
            public function getDocComment(): ?string
            {
                return null;
            }

            /**
             * Get the method name.
             */
            public function getName(): string
            {
                return $this->methodName;
            }

            /**
             * Get the method prototype.
             */
            public function getPrototype(): ClassMemberReflection
            {
                return $this;
            }

            /**
             * Get the method's signatures.
             *
             * @return ParametersAcceptor[]
             */
            public function getVariants(): array
            {
                $returnType = new ObjectType($this->classReflection->getName(), null, $this->classReflection);

                $variants = [
                    new FunctionVariant(TemplateTypeMap::createEmpty(), null, [], false, $returnType),
                ];

                // Unlike upstream, which gives it the has* signatures, trashed() takes the deleted-at value,
                // which set() would take.
                if ($this->methodName === 'trashed') {
                    $valueParameter = $this->classReflection->getMethod('set', new OutOfClassScope)->getVariants()[0]->getParameters()[1];

                    $variants[] = new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$valueParameter], false, $returnType);

                    return $variants;
                }

                $stateParameter = $this->classReflection->getMethod('state', new OutOfClassScope)->getVariants()[0]->getParameters()[0];

                if (Str::startsWith($this->methodName, 'for')) {
                    $variants[] = new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$stateParameter], false, $returnType);

                    return $variants;
                }

                $countParameter = $this->classReflection->getMethod('count', new OutOfClassScope)->getVariants()[0]->getParameters()[0];
                $sequenceParameter = $this->classReflection->getMethod('forEachSequence', new OutOfClassScope)->getVariants()[0]->getParameters()[0];

                $variants[] = new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$countParameter], false, $returnType);
                $variants[] = new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$stateParameter], false, $returnType);
                $variants[] = new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$countParameter, $stateParameter], false, $returnType);
                // Unlike upstream, several arrays are accepted: __call() passes them to forEachSequence().
                $variants[] = new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$sequenceParameter], true, $returnType);

                return $variants;
            }

            /**
             * Determine whether the method is deprecated.
             */
            public function isDeprecated(): TrinaryLogic
            {
                return TrinaryLogic::createNo();
            }

            /**
             * Get the deprecation description.
             */
            public function getDeprecatedDescription(): ?string
            {
                return null;
            }

            /**
             * Determine whether the method is final.
             */
            public function isFinal(): TrinaryLogic
            {
                return TrinaryLogic::createNo();
            }

            /**
             * Determine whether the method is internal.
             */
            public function isInternal(): TrinaryLogic
            {
                return TrinaryLogic::createNo();
            }

            /**
             * Get the type the method throws.
             */
            public function getThrowType(): ?Type
            {
                return null;
            }

            /**
             * Determine whether the method has side effects.
             */
            public function hasSideEffects(): TrinaryLogic
            {
                return TrinaryLogic::createMaybe();
            }
        };
    }
}
