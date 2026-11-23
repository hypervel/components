<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\PHPStan\Reflection\ReflectionHelper;
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

use function array_keys;
use function array_values;
use function implode;
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
                if (in_array($method, $methods, true) && ReflectionHelper::isFacade($node->class, $facade, $alias)) {
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
     * A choice between arrays stays one entry, which getMatchingJobs() follows path by path.
     *
     * @return list<Type>
     */
    private function getIterableJobTypes(Type $iterable): array
    {
        if ($iterable instanceof UnionType && $iterable->isArray()->yes()) {
            return [$iterable];
        }

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
        // A choice that includes a chain stays one entry, which getMatchingJobs() follows path by path.
        if ($entry instanceof UnionType && ! $entry->isArray()->no()) {
            return [$entry];
        }

        if ($entry->isArray()->yes()) {
            return $this->getIterableJobTypes($entry);
        }

        return [$entry];
    }

    /**
     * Get the jobs of an entry that match, or none when the match is not certain.
     *
     * A chain or a list matches through any of its jobs. A choice that includes a chain matches when
     * every alternative does, or when any does unless the match must hold on every path.
     *
     * @param callable(Type): bool $matches
     * @return list<Type>
     */
    private function getMatchingJobs(Type $entry, callable $matches, bool $onEveryPath): array
    {
        if ($entry instanceof UnionType && ! $entry->isArray()->no()) {
            $matched = [];

            foreach ($entry->getTypes() as $alternative) {
                $jobs = $this->getMatchingJobs($alternative, $matches, $onEveryPath);

                if ($jobs === [] && $onEveryPath) {
                    return [];
                }

                $matched = [...$matched, ...$jobs];
            }

            return $matched;
        }

        if ($entry->isArray()->yes()) {
            $matched = [];

            foreach ($this->getIterableJobTypes($entry) as $job) {
                $matched = [...$matched, ...$this->getMatchingJobs($job, $matches, $onEveryPath)];
            }

            return $matched;
        }

        return $matches($entry) ? [$entry] : [];
    }

    /**
     * Get the class names of the jobs, each once.
     *
     * @param list<Type> $jobs
     */
    private function describeJobClasses(array $jobs): string
    {
        $names = [];

        foreach ($jobs as $job) {
            foreach ($job->getObjectClassNames() as $name) {
                $names[$name] = true;
            }
        }

        return implode('|', array_keys($names));
    }
}
