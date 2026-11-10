<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Container\Container;
use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateConfigOffsetCollector;
use Hypervel\PHPStan\Collectors\WorkerStateParameterCollector;
use Hypervel\PHPStan\Collectors\WorkerStateWriteCollector;
use Hypervel\PHPStan\Concerns\HasContainer;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_all;
use function array_any;
use function array_fill_keys;
use function array_find;
use function array_keys;
use function sprintf;
use function strtolower;

use const PHP_INT_MAX;

/**
 * Reports request and operation state kept in worker-shared objects, static properties and config,
 * and request-keyed caches that grow for the worker's lifetime.
 *
 * One rule serves the four switches, because PHPStan cannot register a rule when any of several
 * parameters is on, and so one write gets one diagnostic: an unbounded cache before shared or static
 * state. Lifetimes come from the booted application, so they are applied here rather than collected.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 * @phpstan-import-type RouteAction from WorkerStateHelper
 *
 * @phpstan-type Diagnostic array{identifier: string, message: string, tip: string}
 *
 * @implements Rule<CollectedDataNode>
 */
#[AutowiredService]
class WorkerStateRule implements Rule
{
    use HasContainer;

    /**
     * How diagnostics describe each kind of request or operation state.
     */
    private const array STATE_LABELS = [
        'request' => 'the current request',
        'response' => 'a response',
        'user' => 'a user',
        'file' => 'an uploaded file',
        'model' => 'a model',
    ];

    private const string SHARED_TIP = 'Pass request values to the methods that use them or keep them in CoroutineContext. If the whole object belongs to one request, mark it #[Scoped].';

    private const string STATIC_TIP = 'Keep per-request values in CoroutineContext, and set static state only during boot.';

    private const string CACHE_TIP = 'Use a cache store with an expiry, or keep per-request values in CoroutineContext.';

    private const string CONFIG_TIP = 'Set configuration during boot, and keep per-request values in CoroutineContext.';

    /**
     * Create a new worker state rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private WorkerStateHelper $helper,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * Report the collected writes and config changes that keep request state for the worker.
     *
     * @param CollectedDataNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->helper->isEnabled()) {
            return [];
        }

        $classes = $node->get(WorkerStateClassCollector::class);
        $configWrites = $node->get(WorkerStateConfigOffsetCollector::class);
        $abstracts = [];
        $singletons = [];
        $queued = [];
        $actions = [];

        foreach ($node->get(WorkerStateCallCollector::class) as $file => $calls) {
            foreach ($calls as $call) {
                if ($call['type'] === 'resolution') {
                    $abstracts += array_fill_keys($call['abstracts'], true);
                } elseif ($call['type'] === 'action') {
                    $actions[$file][] = $call;
                } else {
                    $configWrites[$file][] = $call;
                }
            }
        }

        foreach ($classes as $fileClasses) {
            foreach ($fileClasses as $class) {
                if ($class['singleton']) {
                    $singletons[] = $class['class'];
                }

                if ($class['queued']) {
                    $queued[] = $class['class'];
                }
            }
        }

        // The first position each method or route action reassigns each parameter at.
        $reassigned = [];

        foreach ($node->get(WorkerStateParameterCollector::class) as $file => $parameters) {
            foreach ($parameters as $parameter) {
                $key = $this->getFunctionKey($parameter['function'], $file, $actions[$file] ?? []);
                $position = $parameter['function']['position'];

                if ($key !== null && $position < ($reassigned[$key][$parameter['parameter']] ?? PHP_INT_MAX)) {
                    $reassigned[$key][$parameter['parameter']] = $position;
                }
            }
        }

        // The analysis always runs on the framework container: the booted application or a test's own.
        /** @var Container $container */
        $container = $this->getContainer();
        $lifetimes = new WorkerLifetimes($this->reflectionProvider, $container, array_keys($abstracts), $singletons, $queued);
        $errors = [];

        foreach ($node->get(WorkerStateWriteCollector::class) as $file => $writes) {
            foreach ($writes as $write) {
                $context = $this->getContext($write['function'], $actions[$file] ?? [], $lifetimes);

                // A constructor parameter keeps no state when every shared build passes a fresh value
                // and the constructor writes it before giving it another one.
                $fresh = $write['parameter'] !== null
                    && ! $this->isReassigned($write['function'], $write['parameter'], $file, $actions[$file] ?? [], $reassigned)
                    && $lifetimes->isFreshParameter($write['class'], $write['parameter']);

                $diagnostic = $this->checkWrite($write, $context, $fresh ? null : $write['state'], $lifetimes);

                if ($diagnostic !== null) {
                    $errors[] = $this->buildError($diagnostic, $file, $write['line']);
                }
            }
        }

        foreach ($classes as $file => $fileClasses) {
            foreach ($fileClasses as $class) {
                if (! $this->helper->checkWorkerSharedState || ! $lifetimes->isShared($class['class'])) {
                    continue;
                }

                foreach ($class['captures'] as $capture) {
                    if (! $lifetimes->isFreshParameter($class['class'], $capture['property'])) {
                        $errors[] = $this->buildError($this->sharedState($class['class'], $capture['property'], $capture['state']), $file, $capture['line']);
                    }
                }
            }
        }

        if ($this->helper->noRuntimeConfigMutation) {
            foreach ($configWrites as $file => $writes) {
                foreach ($writes as $write) {
                    $context = $this->getContext($write['function'], $actions[$file] ?? [], $lifetimes);

                    // A resolution is the global repository when the booted aliases say so, and a parameter
                    // where the container injects it and the function still holds it.
                    if ($context === null
                        || ($write['abstracts'] !== null && ! array_all($write['abstracts'], $lifetimes->isGlobalConfig(...)))
                        || ($write['parameter'] !== null && ! $this->isInjectedConfig($write['function'], $write['parameter'], $file, $actions[$file] ?? [], $reassigned, $lifetimes))) {
                        continue;
                    }

                    $errors[] = $this->buildError([
                        'identifier' => 'hypervel.runtimeConfigMutation',
                        'message' => sprintf('Configuration is changed %s, which changes it for the whole worker.', $this->describeContext($context)),
                        'tip' => self::CONFIG_TIP,
                    ], $file, $write['line']);
                }
            }
        }

        // Reused errors stay valid only while the classes the lifetimes were read from are unchanged.
        foreach ($lifetimes->getClasses() as $class) {
            $scope->trackClassDependency($class);
        }

        return $errors;
    }

    /**
     * Get the diagnostic for a collected property write, choosing an unbounded cache before shared
     * or static state, or null when the write is fine.
     *
     * @param array{static: bool, class: string, property: string, line: int, function: FunctionFacts, state: null|string, parameter: null|string, cacheKey: bool} $write
     * @param null|string $state the state the write keeps, unless it is a fresh constructor parameter
     *
     * @return null|Diagnostic
     */
    private function checkWrite(array $write, ?string $context, ?string $state, WorkerLifetimes $lifetimes): ?array
    {
        $shared = ! $write['static'] && $lifetimes->isShared($write['class']);

        if ($this->helper->checkUnboundedWorkerCaches && $write['cacheKey'] && ($write['static'] || $shared)) {
            return [
                'identifier' => 'hypervel.unboundedWorkerCache',
                'message' => sprintf(
                    '%s %s::$%s is keyed by request or user data, so it can grow for the life of the worker.',
                    $write['static'] ? 'Static property' : 'Property',
                    $write['class'],
                    $write['property'],
                ),
                'tip' => self::CACHE_TIP,
            ];
        }

        if (! $write['static'] && $this->helper->checkWorkerSharedState) {
            if ($shared && $state !== null) {
                return $this->sharedState($write['class'], $write['property'], $state);
            }

            // A booted middleware or action is judged by the instances that handle it, which may be
            // other subclasses than the shared ones.
            $handledShared = $write['function']['closure'] || $write['function']['context'] !== null
                ? $shared
                : $write['function']['method'] !== null && $lifetimes->isContextShared($write['class'], $write['function']['method']);

            if ($context !== null && $handledShared && $write['function']['method'] !== null) {
                return [
                    'identifier' => 'hypervel.workerSharedState',
                    'message' => sprintf(
                        '%s::%s() writes $this->%s %s, but the container shares %s across concurrent requests.',
                        $write['class'],
                        $write['function']['method'],
                        $write['property'],
                        $this->describeContext($context),
                        $write['class'],
                    ),
                    'tip' => 'Keep per-request values in local variables or CoroutineContext.',
                ];
            }
        }

        if ($write['static'] && $this->helper->checkStaticStateMutation) {
            if ($state !== null) {
                return [
                    'identifier' => 'hypervel.staticStateMutation',
                    'message' => sprintf('Static property %s::$%s keeps %s, which the whole worker then shares.', $write['class'], $write['property'], self::STATE_LABELS[$state]),
                    'tip' => self::STATIC_TIP,
                ];
            }

            if ($context !== null) {
                return [
                    'identifier' => 'hypervel.staticStateMutation',
                    'message' => sprintf(
                        'Static property %s::$%s is written %s, but static properties are shared by the whole worker.',
                        $write['class'],
                        $write['property'],
                        $this->describeContext($context),
                    ),
                    'tip' => self::STATIC_TIP,
                ];
            }
        }

        return null;
    }

    /**
     * Get the diagnostic for a worker-shared property that keeps request or operation state.
     *
     * @return Diagnostic
     */
    private function sharedState(string $class, string $property, string $state): array
    {
        return [
            'identifier' => 'hypervel.workerSharedState',
            'message' => sprintf('Property %s::$%s keeps %s, but the container shares %s for the whole worker.', $class, $property, self::STATE_LABELS[$state], $class),
            'tip' => self::SHARED_TIP,
        ];
    }

    /**
     * Build the error for a diagnostic at the file and line.
     *
     * @param Diagnostic $diagnostic
     */
    private function buildError(array $diagnostic, string $file, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($diagnostic['message'])
            ->tip($diagnostic['tip'])
            ->identifier($diagnostic['identifier'])
            ->file($file)
            ->line($line)
            ->build();
    }

    /**
     * Get the operation the function always handles: its collected context, the route action it is,
     * or a booted middleware or controller action method.
     *
     * @param FunctionFacts $function
     * @param list<RouteAction> $actions the file's route actions
     */
    private function getContext(array $function, array $actions, WorkerLifetimes $lifetimes): ?string
    {
        if ($function['closure']) {
            return $this->findAction($function, $actions) === null ? null : 'action';
        }

        if ($function['context'] !== null || $function['class'] === null || $function['method'] === null) {
            return $function['context'];
        }

        return $lifetimes->getContext($function['class'], $function['method']);
    }

    /**
     * Find the route action the closure code runs directly in, outside any function nested in it.
     *
     * @param FunctionFacts $function
     * @param list<RouteAction> $actions the file's route actions
     *
     * @return null|RouteAction
     */
    private function findAction(array $function, array $actions): ?array
    {
        $position = $function['position'];

        return array_find($actions, static fn (array $action): bool => $position >= $action['start']
            && $position <= $action['end']
            && ! array_any($action['nested'], static fn (array $range): bool => $position >= $range[0] && $position <= $range[1]));
    }

    /**
     * Get the key identifying a method or route action, or null for any other function.
     *
     * @param FunctionFacts $function
     * @param list<RouteAction> $actions the file's route actions
     */
    private function getFunctionKey(array $function, string $file, array $actions): ?string
    {
        if ($function['closure']) {
            $action = $this->findAction($function, $actions);

            return $action === null ? null : $file . '#' . $action['start'];
        }

        return $function['class'] === null || $function['method'] === null ? null : strtolower($function['class'] . '::' . $function['method']);
    }

    /**
     * Determine whether the function gives the parameter another value before the code at its position.
     *
     * @param FunctionFacts $function
     * @param list<RouteAction> $actions the file's route actions
     * @param array<string, array<string, int>> $reassigned
     */
    private function isReassigned(array $function, string $parameter, string $file, array $actions, array $reassigned): bool
    {
        $key = $this->getFunctionKey($function, $file, $actions);

        return $key !== null && ($reassigned[$key][$parameter] ?? PHP_INT_MAX) < $function['position'];
    }

    /**
     * Determine whether the container injects the global configuration repository into the function's
     * parameter, and the function still holds it: a controller action, a route closure or a job's handler.
     *
     * @param FunctionFacts $function
     * @param list<RouteAction> $actions the file's route actions
     * @param array<string, array<string, int>> $reassigned
     */
    private function isInjectedConfig(array $function, string $parameter, string $file, array $actions, array $reassigned, WorkerLifetimes $lifetimes): bool
    {
        if ($this->isReassigned($function, $parameter, $file, $actions, $reassigned)) {
            return false;
        }

        if ($function['closure']) {
            $class = $this->findAction($function, $actions)['configParameters'][$parameter] ?? null;

            return $class !== null && $lifetimes->isGlobalConfig($class);
        }

        return $function['class'] !== null
            && $function['method'] !== null
            && $lifetimes->isConfigParameter($function['class'], $function['method'], $parameter);
    }

    /**
     * Describe when code in the context runs.
     */
    private function describeContext(string $context): string
    {
        return $context === 'job' ? 'while running a job' : 'while handling a request';
    }
}
