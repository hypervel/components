<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Type;

/**
 * Resolve the model that a call on a model, builder or relation queries, for the rules that check its columns
 * and relations.
 *
 * Unlike upstream, the model comes from the named Builder or Relation template, so custom builders with other
 * type parameters, and non-generic builders whose ancestor fixes the model, resolve too.
 */
#[AutowiredService]
class ModelRuleHelper
{
    /**
     * Return the type the method is called on, with a class-string receiver as its object type.
     */
    public function callerType(MethodCall|StaticCall $node, Scope $scope): Type
    {
        return match (true) {
            $node instanceof MethodCall => $scope->getType($node->var),
            $node->class instanceof Name => $scope->resolveTypeByName($node->class),
            default => $scope->getType($node->class)->getObjectTypeOrClassStringObjectType(),
        };
    }

    /**
     * Return the model a builder, relation or model queries.
     */
    public function queriedModel(ClassReflection $callerClass, Type $callerType): ?Type
    {
        return match (true) {
            $callerClass->is(Relation::class) => $callerType->getTemplateType(Relation::class, 'TRelatedModel'),
            $callerClass->is(EloquentBuilder::class) => $callerType->getTemplateType(EloquentBuilder::class, 'TModel'),
            $callerClass->is(Model::class) => $callerType,
            default => null,
        };
    }
}
