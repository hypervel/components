<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

use function array_values;
use function in_array;

trait InspectsQueuedJobs
{
    /**
     * Determine whether the class uses the given trait, directly or through a parent
     * class or another trait.
     *
     * `getTraits(true)` resolves traits used by the class, by its parent classes,
     * and by those traits, so an inherited trait counts.
     */
    private function usesTrait(ClassReflection $classReflection, string $traitName): bool
    {
        return isset($classReflection->getTraits(true)[$traitName]);
    }

    /**
     * Determine whether the class can be dispatched as it stands.
     *
     * Interfaces and traits are not classes, and an abstract class is never
     * dispatched directly: a concrete subclass supplies (or inherits) whatever the
     * rule asks for, and is checked on its own.
     */
    private function isDispatchableClass(ClassReflection $classReflection): bool
    {
        return ! $classReflection->isInterface()
            && ! $classReflection->isTrait()
            && ! $classReflection->isAbstract();
    }

    /**
     * Determine whether the static call target names the given facade.
     *
     * Matches the facade itself, a subclass of it, and the root namespace alias
     * Hypervel registers for it (`\Bus`, `\DB`, ...).
     */
    private function isFacade(Name $class, string $facade, string $alias): bool
    {
        $className = $class->toString();

        if ($className === $facade || $className === $alias) {
            return true;
        }

        return (new ObjectType($facade))->isSuperTypeOf(new ObjectType($className))->yes();
    }

    /**
     * Get the lowercase name of the method the call makes when it is one of the given
     * facade or receiver methods, or null for any other call.
     *
     * @param array<string, array{class-string, list<string>}> $facadeMethods the facade and its methods, keyed by the facade's alias
     * @param array<class-string, list<string>> $receiverMethods the methods each receiver type provides
     */
    private function getQueueMethod(CallLike $node, Scope $scope, array $facadeMethods, array $receiverMethods): ?string
    {
        if ($node instanceof StaticCall) {
            if (! $node->class instanceof Name || ! $node->name instanceof Identifier) {
                return null;
            }

            $method = $node->name->toLowerString();

            foreach ($facadeMethods as $alias => [$facade, $methods]) {
                if (in_array($method, $methods, true) && $this->isFacade($node->class, $facade, $alias)) {
                    return $method;
                }
            }

            return null;
        }

        // PHPStan also analyzes a nullsafe call as a MethodCall on the non-null receiver.
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $method = $node->name->toLowerString();
            $receiverType = TypeCombinator::removeNull($scope->getType($node->var));

            foreach ($receiverMethods as $receiver => $methods) {
                if (in_array($method, $methods, true) && (new ObjectType($receiver))->isSuperTypeOf($receiverType)->yes()) {
                    return $method;
                }
            }
        }

        return null;
    }

    /**
     * Get the jobs a bulk or batch method receives, each with the node to report it at.
     *
     * An array literal gives each element, recursing into nested arrays (chains within a
     * batch). Any other value gives the jobs of its iterable type at the call, or its own
     * type for a single job, which the entry points wrap.
     *
     * @return list<array{Type, Node}>
     */
    private function getQueuedJobTypes(Expr $jobs, CallLike $node, Scope $scope): array
    {
        if (! $jobs instanceof Array_) {
            $type = $scope->getType($jobs);
            $jobTypes = [];

            foreach ($type->isIterable()->yes() ? $this->getIterableJobTypes($type) : [$type] as $jobType) {
                $jobTypes[] = [$jobType, $node];
            }

            return $jobTypes;
        }

        $jobTypes = [];

        foreach ($jobs->items as $item) {
            if ($item->value instanceof Array_) {
                foreach ($this->getQueuedJobTypes($item->value, $node, $scope) as $jobType) {
                    $jobTypes[] = $jobType;
                }

                continue;
            }

            $type = $scope->getType($item->value);

            foreach ($item->unpack ? $this->getIterableJobTypes($type) : $this->getEntryJobTypes($type) as $jobType) {
                $jobTypes[] = [$jobType, $item->value];
            }
        }

        return $jobTypes;
    }

    /**
     * Get the job types of an iterable's entries.
     *
     * Each value of a constant array counts on its own, so one job's class cannot hide another's.
     *
     * @return list<Type>
     */
    private function getIterableJobTypes(Type $iterable): array
    {
        if ($iterable->isConstantArray()->yes()) {
            $entries = [];

            foreach ($iterable->getConstantArrays() as $array) {
                foreach ($array->getValueTypes() as $valueType) {
                    $entries[] = $valueType;
                }
            }
        } else {
            $entries = [$iterable->getIterableValueType()];
        }

        $jobTypes = [];

        foreach ($entries as $entry) {
            foreach ($this->getEntryJobTypes($entry) as $jobType) {
                // Equal jobs are reported once at the call.
                $jobTypes[$jobType->describe(VerbosityLevel::cache())] = $jobType;
            }
        }

        return array_values($jobTypes);
    }

    /**
     * Get the job types of an entry: the job itself, or the jobs of a chain within a batch.
     *
     * Bulk dispatch rejects arrays, so reading them as chains there changes nothing it accepts.
     *
     * @return list<Type>
     */
    private function getEntryJobTypes(Type $entry): array
    {
        if ($entry->isArray()->yes()) {
            return $this->getIterableJobTypes($entry);
        }

        // A job-or-chain entry stays one value whose classes include the chain's jobs, so each rule
        // still decides from every class the entry may hold.
        if ($entry instanceof UnionType && $entry->isArray()->maybe()) {
            $alternatives = [];

            foreach ($entry->getTypes() as $member) {
                $alternatives = [...$alternatives, ...$this->getEntryJobTypes($member)];
            }

            return [TypeCombinator::union(...$alternatives)];
        }

        return [$entry];
    }
}
