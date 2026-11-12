<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Properties\ModelHelper;
use Hypervel\PHPStan\Properties\ModelRelationsExtension;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use ReflectionException;

use function array_all;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function sprintf;
use function strtolower;

/**
 * Report relation names, such as those an eager load or relationship query names, that a model does not define.
 */
#[AutowiredService]
class RelationExistenceHelper
{
    /**
     * Create a relation existence helper.
     */
    public function __construct(
        private ModelRuleHelper $modelRuleHelper,
        private ModelHelper $modelHelper,
        private ModelRelationsExtension $modelRelations,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Report each relation, or segment of a dotted relation path, that the queried model does not define.
     *
     * @param DependencyTracker&Scope $scope
     * @param bool $selectsColumns whether a name may end with a column selection, such as `posts:id`
     * @param bool $followsPaths whether a dotted name is a path through related models
     * @return list<IdentifierRuleError>
     */
    public function check(Type $relations, Type $modelType, Node $node, Scope $scope, bool $aggregate = false, bool $selectsColumns = true, bool $followsPaths = true): array
    {
        $errors = [];

        foreach (array_unique($this->relationNames($relations, $selectsColumns)) as $name) {
            if ($aggregate) {
                $alias = explode(' ', $name);

                if (count($alias) === 3 && strtolower($alias[1]) === 'as') {
                    $name = $alias[0];
                }
            }

            $calledOnType = $modelType;

            // Aggregates call the relation method directly; they do not traverse dotted paths.
            foreach ($aggregate || ! $followsPaths ? [$name] : explode('.', $name) as $relationName) {
                $concreteModel = $this->concreteModel($calledOnType);

                if ($concreteModel === null) {
                    break;
                }

                $modelReflection = $concreteModel->getObjectClassReflections()[0];
                $variant = $modelReflection->hasMethod($relationName)
                    ? ParametersAcceptorSelector::selectFromArgs($scope, [], $modelReflection->getMethod($relationName, $scope)->getVariants())
                    : null;

                // The model calls a relation method without arguments, so one that requires them is not a relation.
                if (
                    $variant !== null
                    && array_all($variant->getParameters(), static fn (ParameterReflection $parameter): bool => $parameter->isOptional())
                    && (new ObjectType(Relation::class))->isSuperTypeOf($variant->getReturnType())->yes()
                ) {
                    // Simulate a call so dynamic return type extensions can resolve the related model. Unlike upstream,
                    // the call is on the model's type, keeping a generic model's type arguments.
                    $calledOnType = $scope->getType(new MethodCall(new TypeExpr($concreteModel), $relationName));

                    continue;
                }

                // Unlike upstream, a method without a declared relation return may be a relation of unknown type, which ends the path.
                if ($this->modelRelations->isRelationMethod($modelReflection, $relationName)->maybe()) {
                    break;
                }

                $this->runtimeInputs->track($scope);

                // Unlike upstream, a relation resolveRelationUsing() registers exists. A typed one continues the path
                // above; one without a declared relation return ends it here.
                if (! $this->hasDynamicRelation($modelReflection, $relationName)) {
                    $errors[$modelReflection->getName() . '::' . $relationName] = RuleErrorBuilder::message(sprintf(
                        "Relation '%s' is not found in %s model.",
                        $relationName,
                        $modelReflection->getName(),
                    ))->identifier('hypervel.relationExistence')->line($node->getStartLine())->build();
                }

                break;
            }
        }

        return array_values($errors);
    }

    /**
     * Return the single concrete model a model, builder or relation type queries, or null when it is not known.
     */
    private function concreteModel(Type $type): ?Type
    {
        $callerClasses = $type->getObjectClassReflections();

        if (count($callerClasses) !== 1) {
            return null;
        }

        $modelType = $this->modelRuleHelper->queriedModel($callerClasses[0], $type);

        if ($modelType === null) {
            return null;
        }

        $modelType = TypeCombinator::removeNull($modelType);
        $modelClasses = $modelType->getObjectClassReflections();

        // An abstract model or a union stands for models whose relations differ.
        return count($modelClasses) === 1 && ! $modelClasses[0]->isAbstract() ? $modelType : null;
    }

    /**
     * Determine whether resolveRelationUsing() registered the relation, reading the registry without running it.
     */
    private function hasDynamicRelation(ClassReflection $modelReflection, string $relationName): bool
    {
        try {
            return $this->modelHelper->getRelationResolver($modelReflection, $relationName) !== null;
        } catch (ReflectionException) {
            // A model that cannot be instantiated has unknown relations.
            return true;
        }
    }

    /**
     * Return the relation names an argument lists, with nested names prefixed by their parent's path.
     *
     * Eager loading parses column selections such as `posts:id` only for names without a callback. A name paired
     * with a callback is used verbatim, so it is returned unchanged.
     *
     * @return list<string>
     */
    private function relationNames(Type $type, bool $selectsColumns, string $prefix = ''): array
    {
        $names = [];

        foreach ($type->getConstantStrings() as $name) {
            $names[] = $prefix . ($selectsColumns ? explode(':', $name->getValue())[0] : $name->getValue());
        }

        foreach ($type->getConstantArrays() as $array) {
            foreach ($array->getKeyTypes() as $index => $key) {
                $value = $array->getValueTypes()[$index];

                if ($key->isString()->yes()) {
                    $name = $prefix . $key->getValue();
                    $names[] = $value->isArray()->no() ? $name : explode(':', $name)[0];

                    if ($value->isArray()->yes()) {
                        $names = array_merge($names, $this->relationNames($value, true, explode(':', $name)[0] . '.'));
                    }
                } else {
                    foreach ($value->getConstantStrings() as $name) {
                        $names[] = $prefix . explode(':', $name->getValue())[0];
                    }
                }
            }
        }

        return $names;
    }
}
