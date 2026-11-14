<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Container\Attributes\Singleton;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

use function array_all;
use function array_is_list;
use function array_keys;
use function array_walk_recursive;
use function is_array;
use function is_string;

/**
 * Collects the classes that are worker-lifetime roots by declaration, the request or operation
 * state their promoted constructor parameters keep, and the observers models register when they boot.
 *
 * @phpstan-type ClassFacts array{class: string, singleton: bool, queued: bool, captures: list<array{property: string, line: int, state: string}>, observers: list<string>, events: list<string>}
 *
 * @implements Collector<InClassNode, ClassFacts>
 */
#[AutowiredService]
class WorkerStateClassCollector implements Collector
{
    /**
     * The events every model makes observable, as HasEvents::getObservableEvents() lists them.
     */
    private const array MODEL_EVENTS = [
        'retrieved', 'creating', 'created', 'updating', 'updated',
        'saving', 'saved', 'restoring', 'restored', 'replicating',
        'trashed', 'deleting', 'deleted', 'forceDeleting', 'forceDeleted',
    ];

    /**
     * The model methods that decide which #[ObservedBy] observers handle which events.
     */
    private const array OBSERVER_REGISTRATION = [
        'bootHasEvents', 'observe', 'resolveObserveAttributes', 'registerObserver', 'getObservableEvents',
    ];

    /**
     * Create a new collector instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private WorkerStateHelper $helper,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Collect the class's root declarations, promoted state captures and model observers.
     *
     * @param InClassNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return null|ClassFacts
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $class = $node->getClassReflection();

        if (! $this->helper->isEnabled() || ! $class->isClass()) {
            return null;
        }

        $concrete = ! $class->isAbstract();
        $singleton = $concrete && $class->getNativeReflection()->getAttributes(Singleton::class) !== [];
        $queued = $concrete && $class->is(ShouldQueue::class);
        $captures = [];

        $constructor = $node->getOriginalNode()->getMethod('__construct');

        // A constructor marked as boot-time or test-only keeps its parameters by design.
        $parameters = $constructor === null || $this->helper->getLifetimeAttribute($class->getNativeMethod('__construct')) !== null
            ? []
            : $constructor->params;

        foreach ($parameters as $parameter) {
            if ($parameter->flags === 0 || ! $parameter->var instanceof Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            $state = $this->helper->getState($class->getNativeProperty($parameter->var->name)->getReadableType());

            if ($state !== null) {
                $captures[] = ['property' => $parameter->var->name, 'line' => $parameter->getStartLine(), 'state' => $state];
            }
        }

        [$observers, $events] = $concrete && $class->is(Model::class) ? $this->getObservers($class, $scope) : [[], []];

        if (! $singleton && ! $queued && $captures === [] && $observers === []) {
            return null;
        }

        return [
            'class' => $class->getName(),
            'singleton' => $singleton,
            'queued' => $queued,
            'captures' => $captures,
            'observers' => $observers,
            'events' => $events,
        ];
    }

    /**
     * Get the observers #[ObservedBy] registers when the model boots, with the events they can handle.
     *
     * HasEvents registers them lazily at the model's first use, so the booted dispatcher lacks them.
     * A model that customizes the registration, or whose observable events are not constant, gets none.
     *
     * @param DependencyTracker&Scope $scope
     *
     * @return array{list<string>, list<string>}
     */
    private function getObservers(ClassReflection $model, Scope $scope): array
    {
        $observers = [];

        // resolveObserveAttributes() merges each ancestor's own attributes below Model.
        foreach ([$model, ...$model->getParents()] as $ancestor) {
            if ($ancestor->getName() === Model::class) {
                break;
            }

            foreach ($ancestor->getNativeReflection()->getAttributes(ObservedBy::class) as $attribute) {
                $arguments = $attribute->getArguments();

                array_walk_recursive($arguments, static function (mixed $observer) use (&$observers): void {
                    if (is_string($observer)) {
                        $observers[$observer] = true;
                    }
                });
            }

            // Exported nodes keep the argument expressions, not the values of constants they read.
            foreach (SourceFiles::forAttributeArguments($this->reflectionProvider, $ancestor, ObservedBy::class) as $file) {
                if ($file !== $scope->getFile()) {
                    $scope->trackFileDependency($file);
                }
            }
        }

        if ($observers === []
            || ! array_all(self::OBSERVER_REGISTRATION, static fn (string $method): bool => $model->getNativeMethod($method)->getDeclaringClass()->getName() === Model::class)) {
            return [[], []];
        }

        // Property defaults are not declarations, so their files are tracked.
        $this->runtimeInputs->trackSources($scope, $model->getName(), Model::class);

        $observables = $model->getNativeReflection()->getProperty('observables')->getDefaultValue();

        if (! is_array($observables) || ! array_is_list($observables) || ! array_all($observables, static fn (mixed $event): bool => is_string($event))) {
            return [[], []];
        }

        return [array_keys($observers), [...self::MODEL_EVENTS, ...$observables]];
    }
}
