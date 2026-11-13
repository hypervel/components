<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Collectors\WorkerStateCallCollector;
use Hypervel\PHPStan\Collectors\WorkerStateClassCollector;
use Hypervel\PHPStan\Collectors\WorkerStateConfigOffsetCollector;
use Hypervel\PHPStan\Collectors\WorkerStateGlobalWriteCollector;
use Hypervel\PHPStan\Collectors\WorkerStateWriteCollector;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_all;
use function sprintf;

/**
 * Reports request and operation state kept in worker-shared objects, static properties, global variables
 * and config, and request-keyed caches that grow for the worker's lifetime.
 *
 * One rule serves the four switches, because PHPStan cannot register a rule when any of several
 * parameters is on, and so one write gets one diagnostic: an unbounded cache before shared or static
 * state. Lifetimes come from the booted application, so they are applied here rather than collected.
 *
 * @phpstan-import-type FunctionFacts from WorkerStateHelper
 *
 * @phpstan-type Diagnostic array{identifier: string, message: string, tip: string}
 *
 * @implements Rule<CollectedDataNode>
 */
#[AutowiredService]
class WorkerStateRule implements Rule
{
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
        if (! $this->helper->hasStateChecks()) {
            return [];
        }

        $analysis = $this->helper->analyze($node);
        $lifetimes = $analysis->lifetimes;
        $configWrites = $node->get(WorkerStateConfigOffsetCollector::class);
        $errors = [];

        foreach ($node->get(WorkerStateCallCollector::class) as $file => $calls) {
            foreach ($calls as $call) {
                if ($call['type'] === 'config') {
                    $configWrites[$file][] = $call;
                }
            }
        }

        foreach ($node->get(WorkerStateWriteCollector::class) as $file => $writes) {
            foreach ($writes as $write) {
                $context = $analysis->getContext($write['function'], $file);

                // A constructor parameter keeps no state when every shared build passes a fresh value
                // and the constructor writes it before giving it another one.
                $fresh = $write['parameter'] !== null
                    && ! $analysis->isReassigned($write['function'], $write['parameter'], $file)
                    && $lifetimes->isFreshParameter($write['class'], $write['parameter']);

                $diagnostic = $this->checkWrite($write, $context, $fresh ? null : $write['state'], $lifetimes);

                if ($diagnostic !== null) {
                    $errors[] = $this->buildError($diagnostic, $file, $write['line']);
                }
            }
        }

        foreach ($node->get(WorkerStateGlobalWriteCollector::class) as $file => $collected) {
            // A write through a bound global name gets what it keeps from the request value written at the same target.
            $states = [];
            $globalWrites = [];

            foreach ($collected as $writes) {
                foreach ($writes as $write) {
                    if ($write['type'] === 'value') {
                        $states[$write['target']] = $write['state'];
                    } else {
                        $globalWrites[] = $write;
                    }
                }
            }

            foreach ($globalWrites as $write) {
                $state = $write['state'] ?? $states[$write['target']] ?? null;
                $context = $analysis->getContext($write['function'], $file);

                $message = match (true) {
                    $state !== null => sprintf('Global variable %s keeps %s, which the whole worker then shares.', $write['name'], self::STATE_LABELS[$state]),
                    $context !== null => sprintf('Global variable %s is written %s, but global variables are shared by the whole worker.', $write['name'], WorkerStateAnalysis::describeContext($context)),
                    default => null,
                };

                if ($message !== null) {
                    $errors[] = $this->buildError(['identifier' => 'hypervel.staticStateMutation', 'message' => $message, 'tip' => self::STATIC_TIP], $file, $write['line']);
                }
            }
        }

        foreach ($node->get(WorkerStateClassCollector::class) as $file => $fileClasses) {
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
                    $context = $analysis->getContext($write['function'], $file);

                    // A resolution is the global repository when the booted aliases say so, a parameter
                    // where the container injects it and the function still holds it, and a promoted
                    // property where the container injects it into the constructor of the instance it builds.
                    if ($context === null
                        || ($write['abstracts'] !== null && ! array_all($write['abstracts'], $lifetimes->isGlobalConfig(...)))
                        || ($write['parameter'] !== null && ! $analysis->isInjectedConfig($write['function'], $write['parameter'], $file))
                        || ($write['property'] !== null && ! $analysis->isPromotedConfig($write['function'], ...$write['property']))) {
                        continue;
                    }

                    $errors[] = $this->buildError([
                        'identifier' => 'hypervel.runtimeConfigMutation',
                        'message' => sprintf('Configuration is changed %s, which changes it for the whole worker.', WorkerStateAnalysis::describeContext($context)),
                        'tip' => self::CONFIG_TIP,
                    ], $file, $write['line']);
                }
            }
        }

        // Reused errors stay valid only while the booted application and the classes the lifetimes
        // were read from are unchanged.
        $analysis->track($scope);

        return $errors;
    }

    /**
     * Get the diagnostic for a collected property write, choosing an unbounded cache before shared
     * or static state, or null when the write is fine.
     *
     * @param array{static: bool, class: string, property: string, line: int, function: FunctionFacts, state: null|string, parameter: null|string, cacheKey: bool, copied: bool} $write
     * @param null|string $state the state the write keeps, unless it is a fresh constructor parameter
     *
     * @return null|Diagnostic
     */
    private function checkWrite(array $write, ?string $context, ?string $state, WorkerLifetimes $lifetimes): ?array
    {
        $method = $write['function']['method'];

        // A copy of a queued listener, for a job or for reading its options, has its own properties and arrays.
        $copyOnly = $write['copied'] && $method !== null && $lifetimes->runsOnCopy($write['class'], $method);
        $shared = ! $write['static'] && ! $copyOnly && $lifetimes->isShared($write['class']);

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
                : $method !== null && ! $copyOnly && $lifetimes->isContextShared($write['class'], $method);

            if ($context !== null && $handledShared && $method !== null) {
                return [
                    'identifier' => 'hypervel.workerSharedState',
                    'message' => sprintf(
                        '%s::%s() writes $this->%s %s, but the container shares %s across concurrent requests.',
                        $write['class'],
                        $method,
                        $write['property'],
                        WorkerStateAnalysis::describeContext($context),
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
                        WorkerStateAnalysis::describeContext($context),
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
}
