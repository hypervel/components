<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Container\Container;
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;

use function array_any;
use function array_fill_keys;
use function array_find;
use function array_keys;
use function array_map;
use function strtolower;

use const PHP_INT_MAX;

/**
 * The worker-state model of one analysis, shared by the rules that judge collected code by the
 * request, job or operation it runs in: the booted lifetimes, with the collected route closures and
 * parameter reassignments.
 *
 * Lifetimes come from every analyzed file and the booted application, so the model is built once from
 * the collected data rather than per file.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 * @phpstan-import-type RouteAction from WorkerStateHelper
 *
 * @internal
 */
class WorkerStateAnalysis
{
    /**
     * The worker lifetimes of the booted application and the collected roots.
     */
    public readonly WorkerLifetimes $lifetimes;

    /**
     * Each file's route closures.
     *
     * @var array<string, list<RouteAction>>
     */
    private array $actions = [];

    /**
     * The first position each method or route action reassigns each parameter at.
     *
     * @var array<string, array<string, int>>
     */
    private array $reassigned = [];

    /**
     * Build the model from the collected data and the booted container.
     */
    public function __construct(
        CollectedDataNode $node,
        ReflectionProvider $reflectionProvider,
        Container $container,
        private RuntimeInputs $runtimeInputs,
    ) {
        $abstracts = [];
        $singletons = [];
        $queued = [];
        $helperCalls = [];

        foreach ($node->get(WorkerStateCallCollector::class) as $file => $calls) {
            foreach ($calls as $call) {
                if ($call['type'] === 'resolution') {
                    $abstracts += array_fill_keys($call['abstracts'], true);
                } elseif ($call['type'] === 'action') {
                    $this->actions[$file][] = $call;
                } elseif ($call['type'] === 'call') {
                    $helperCalls[] = $call;
                }
            }
        }

        $observers = [];

        foreach ($node->get(WorkerStateClassCollector::class) as $fileClasses) {
            foreach ($fileClasses as $class) {
                if ($class['singleton']) {
                    $singletons[] = $class['class'];
                }

                if ($class['queued']) {
                    $queued[] = $class['class'];
                }

                foreach ($class['observers'] as $observer) {
                    $observers[$observer] = ($observers[$observer] ?? []) + array_fill_keys($class['events'], true);
                }
            }
        }

        foreach ($node->get(WorkerStateParameterCollector::class) as $file => $parameters) {
            foreach ($parameters as $parameter) {
                $key = $this->getFunctionKey($parameter['function'], $file);
                $position = $parameter['function']['position'];

                if ($key !== null && $position < ($this->reassigned[$key][$parameter['parameter']] ?? PHP_INT_MAX)) {
                    $this->reassigned[$key][$parameter['parameter']] = $position;
                }
            }
        }

        $this->lifetimes = new WorkerLifetimes(
            $reflectionProvider,
            $container,
            array_keys($abstracts),
            $singletons,
            $queued,
            $helperCalls,
            array_map(array_keys(...), $observers),
        );
    }

    /**
     * Declare that a rule's errors depend on the booted application and the classes the lifetimes
     * were read from, after the rule's queries have read them.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function track(Scope $scope): void
    {
        // Lifetimes come from the booted bindings, routes, middleware, listeners and Bus handlers.
        $this->runtimeInputs->track($scope);

        foreach ($this->lifetimes->getClasses() as $class) {
            $scope->trackClassDependency($class);
        }

        foreach ($this->lifetimes->getDefaultSources() as $class) {
            $this->runtimeInputs->trackSources($scope, $class, null);
        }
    }

    /**
     * Describe when code in the context runs.
     */
    public static function describeContext(string $context): string
    {
        return match ($context) {
            'job' => 'while running a job',
            'operation' => 'during an operation',
            default => 'while handling a request',
        };
    }

    /**
     * Get the operation the function always handles: the route action it is, a booted handler method,
     * a helper such a method or a job calls, or else its collected context.
     *
     * A registration is better evidence than the class alone: a queueable class's handle() registered
     * as a passive observer runs synchronously during its event.
     *
     * @param FunctionFacts $function
     */
    public function getContext(array $function, string $file): ?string
    {
        if ($function['closure']) {
            return $this->findAction($function, $file) === null ? null : 'action';
        }

        if ($function['class'] === null || $function['method'] === null) {
            return $function['context'];
        }

        return $this->lifetimes->getContext($function['class'], $function['method']) ?? $function['context'];
    }

    /**
     * Determine whether the function gives the parameter another value before the code at its position.
     *
     * @param FunctionFacts $function
     */
    public function isReassigned(array $function, string $parameter, string $file): bool
    {
        $key = $this->getFunctionKey($function, $file);

        return $key !== null && ($this->reassigned[$key][$parameter] ?? PHP_INT_MAX) < $function['position'];
    }

    /**
     * Determine whether the container injects the global configuration repository into the function's
     * parameter, and the function still holds it: a controller action, a route closure or a job's handler.
     *
     * @param FunctionFacts $function
     */
    public function isInjectedConfig(array $function, string $parameter, string $file): bool
    {
        if ($this->isReassigned($function, $parameter, $file)) {
            return false;
        }

        if ($function['closure']) {
            $class = $this->findAction($function, $file)['configParameters'][$parameter] ?? null;

            return $class !== null && $this->lifetimes->isGlobalConfig($class);
        }

        return $function['class'] !== null
            && $function['method'] !== null
            && $this->lifetimes->isConfigParameter($function['class'], $function['method'], $parameter);
    }

    /**
     * Determine whether a readonly property the constructor promotes holds the global configuration
     * repository in the method's instances: the container built them and injects that repository.
     *
     * @param FunctionFacts $function
     */
    public function isPromotedConfig(array $function, string $constructor, string $property): bool
    {
        return $function['class'] !== null
            && $function['method'] !== null
            && $this->lifetimes->isContainerBuilt($function['class'], $function['method'])
            && $this->lifetimes->isConfigParameter($constructor, '__construct', $property);
    }

    /**
     * Determine whether a readonly property the constructor promotes holds the instance the container
     * shares for the worker in the method's instances: the container built them and injects that instance.
     *
     * @param FunctionFacts $function
     */
    public function sharesPromotedProperty(array $function, string $constructor, string $property): bool
    {
        return $function['class'] !== null
            && $function['method'] !== null
            && $this->lifetimes->isContainerBuilt($function['class'], $function['method'])
            && $this->lifetimes->sharesInjectedParameter($constructor, '__construct', $property)->yes();
    }

    /**
     * Find the route action the closure code runs directly in, outside any function nested in it.
     *
     * @param FunctionFacts $function
     *
     * @return null|RouteAction
     */
    private function findAction(array $function, string $file): ?array
    {
        $position = $function['position'];

        return array_find($this->actions[$file] ?? [], static fn (array $action): bool => $position >= $action['start']
            && $position <= $action['end']
            && ! array_any($action['nested'], static fn (array $range): bool => $position >= $range[0] && $position <= $range[1]));
    }

    /**
     * Get the key identifying a method or route action, or null for any other function.
     *
     * @param FunctionFacts $function
     */
    private function getFunctionKey(array $function, string $file): ?string
    {
        if ($function['closure']) {
            $action = $this->findAction($function, $file);

            return $action === null ? null : $file . '#' . $action['start'];
        }

        return $function['class'] === null || $function['method'] === null ? null : strtolower($function['class'] . '::' . $function['method']);
    }
}
