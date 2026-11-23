<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Collectors\RestrictedCallCollector;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Attributes\TestsOnly;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_all;
use function class_basename;
use function sprintf;

/**
 * Reports calls to methods marked as boot-time or test-only from code that runs while handling a
 * request, job or operation.
 *
 * A worker-shared restriction is reported only with evidence that the receiver is the instance the
 * booted container shares, so the calls are judged here, against the booted lifetimes.
 *
 * @phpstan-import-type RestrictedCall from RestrictedCallCollector
 *
 * @implements Rule<CollectedDataNode>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkRestrictedCalls%')]
class RestrictedCallRule implements Rule
{
    /**
     * Create a new restricted call rule instance.
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
     * Report the collected restricted calls that run in a request, job or operation.
     *
     * @param CollectedDataNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $collected = $node->get(RestrictedCallCollector::class);

        if ($collected === []) {
            return [];
        }

        $analysis = $this->helper->analyze($node);
        $errors = [];

        foreach ($collected as $file => $nodes) {
            foreach ($nodes as $calls) {
                foreach ($calls as $call) {
                    $context = $analysis->getContext($call['function'], $file);

                    if ($context === null || ($call['whenShared'] && ! $this->reachesSharedInstance($call, $analysis, $file))) {
                        continue;
                    }

                    $tip = match ($call['attribute']) {
                        TestsOnly::class => 'Call it only from tests.',
                        BootOrTestsOnly::class => "Call it while the application boots, such as in a service provider's boot() method, or in a test, and keep per-request state in CoroutineContext or a scoped() binding.",
                        default => "Call it while the application boots, such as in a service provider's boot() method, and keep per-request state in CoroutineContext or a scoped() binding.",
                    };

                    $errors[] = RuleErrorBuilder::message($this->getMessage($call, $context))
                        ->tip($tip . ($call['whenShared'] ? ' Or call it on an instance of your own.' : ''))
                        ->identifier('hypervel.restrictedCall')
                        ->file($file)
                        ->line($call['line'])
                        ->build();
                }
            }
        }

        // Reused errors stay valid only while the booted application and the classes the lifetimes
        // were read from are unchanged.
        $analysis->track($scope);

        return $errors;
    }

    /**
     * Determine whether the call's receiver is the instance the container shares for the worker.
     *
     * A parameter counts where the container injects the shared instance on every call and the method
     * still holds it, and a promoted property where the container builds the instance and injects
     * the shared one into every constructor it runs.
     *
     * @param RestrictedCall $call
     */
    private function reachesSharedInstance(array $call, WorkerStateAnalysis $analysis, string $file): bool
    {
        $receiver = $call['receiver'];
        $function = $call['function'];
        $lifetimes = $analysis->lifetimes;

        if (isset($receiver['abstracts'])) {
            return array_all($receiver['abstracts'], static fn (string $abstract): bool => $lifetimes->sharesAbstract($abstract)->yes());
        }

        if (isset($receiver['parameter'])) {
            return $function['class'] !== null
                && $function['method'] !== null
                && ! $analysis->isReassigned($function, $receiver['parameter'], $file)
                && $lifetimes->sharesInjectedParameter($function['class'], $function['method'], $receiver['parameter'])->yes();
        }

        return isset($receiver['property']) && $analysis->sharesPromotedProperty($function, ...$receiver['property']);
    }

    /**
     * Get the message for a restricted call in the context, with the receiver and arguments that
     * make it restricted.
     *
     * @param RestrictedCall $call
     */
    private function getMessage(array $call, string $context): string
    {
        $condition = '';

        if ($call['condition'] !== null) {
            [$kind, $parameter] = $call['condition'];

            $condition = sprintf(' %swith %s$%s', $call['may'] ? 'possibly ' : '', $kind === 'whenNotDefault' ? 'a non-default ' : '', $parameter);
        }

        return sprintf(
            'Call to #[%s] method %s()%s%s %s.',
            class_basename($call['attribute']),
            $call['method'],
            $call['whenShared'] ? ' on the worker-shared instance' : '',
            $condition,
            WorkerStateAnalysis::describeContext($context),
        );
    }
}
