<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use Hypervel\Queue\SerializesModels;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function sprintf;

/**
 * A queued job (one implementing `ShouldQueue`) that holds an Eloquent model in a
 * property must use the `SerializesModels` trait.
 *
 * A queued job is serialized to the queue store at dispatch and unserialized in
 * the worker. Without `SerializesModels` an Eloquent model property is serialized
 * whole: the full attribute set, loaded relations and casts go onto the wire,
 * bloating the payload, and the job runs against a frozen snapshot taken at
 * dispatch time, so any change made between dispatch and execution is silently
 * lost. `SerializesModels` instead stores just the class name and primary key
 * (plus the loaded relation names) and re-resolves the model fresh from the
 * database when the job runs, keeping the payload small and the data current. A
 * model deleted in the meantime then surfaces as a `ModelNotFoundException`
 * instead of the job operating on stale data.
 *
 * Unlike upstream, which checks only public properties, every non-static
 * property counts, including a parent's private ones, because PHP serializes
 * them all. Properties typed against a model or an Eloquent collection (including
 * nullable unions) count, as `SerializesModels` converts both. An inherited
 * `SerializesModels` (used by the class, a parent, or another trait) satisfies
 * the rule, and a job with its own `__serialize()` decides its payload itself.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkJobSerializesModels%')]
class JobWithModelPropertyDeclaresSerializesModelsRule implements Rule
{
    use InspectsQueuedJobs;

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report a queued job with model properties that does not use SerializesModels.
     *
     * @param InClassNode $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! $this->isDispatchableClass($classReflection)) {
            return [];
        }

        if (! $classReflection->is(ShouldQueue::class)) {
            return [];
        }

        if ($this->usesTrait($classReflection, SerializesModels::class) || $classReflection->hasNativeMethod('__serialize')) {
            return [];
        }

        $modelProperties = $this->modelTypedProperties($classReflection);

        if ($modelProperties === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Job %s has model properties (%s) but does not use SerializesModels.',
                $classReflection->getDisplayName(),
                implode(', ', array_map(static fn (string $name): string => '$' . $name, $modelProperties)),
            ))
                ->tip('Use the Hypervel\Queue\SerializesModels trait.')
                ->identifier('hypervel.jobSerializesModels')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * Get the serialized properties whose type references a model or an Eloquent collection,
     * including inherited properties.
     *
     * An abstract job is not checked itself, so its concrete subclass is checked with all
     * of its model state.
     *
     * @return list<string>
     */
    private function modelTypedProperties(ClassReflection $classReflection): array
    {
        $names = [];

        // A class's own properties exclude its parents' private ones, so each class declares its own.
        for ($declaringClass = $classReflection; $declaringClass !== null; $declaringClass = $declaringClass->getParentClass()) {
            foreach ($declaringClass->getNativeReflection()->getProperties() as $property) {
                if (
                    $property->getDeclaringClass()->getName() !== $declaringClass->getName()
                    || $property->isStatic()
                    || $property->isVirtual()
                ) {
                    continue;
                }

                $type = $declaringClass->getNativeProperty($property->getName())->getReadableType();

                if ($this->typeReferencesModel($type)) {
                    $names[] = $property->getName();
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Determine whether the type references a model or an Eloquent collection.
     */
    private function typeReferencesModel(Type $type): bool
    {
        // A union carrying null reports no object class reflections, so strip it
        // first. The result then flattens `Model` and `Foo|Bar` member by member.
        foreach (TypeCombinator::removeNull($type)->getObjectClassReflections() as $classReflection) {
            if ($classReflection->is(Model::class) || $classReflection->is(EloquentCollection::class)) {
                return true;
            }
        }

        return false;
    }
}
