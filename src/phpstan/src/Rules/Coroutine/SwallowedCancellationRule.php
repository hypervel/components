<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Collectors\SwallowedCancellationCollector;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * Reports a catch that swallows the cancellation of an operation in code that runs while handling a
 * request, job or operation, so the canceled work carries on.
 *
 * @implements Rule<CollectedDataNode>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkCancellationHandling%')]
class SwallowedCancellationRule implements Rule
{
    /**
     * Create a new swallowed cancellation rule instance.
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
     * Report the collected catches that run in a request, job or operation.
     *
     * @param CollectedDataNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $collected = $node->get(SwallowedCancellationCollector::class);

        if ($collected === []) {
            return [];
        }

        $analysis = $this->helper->analyze($node);
        $errors = [];

        foreach ($collected as $file => $catches) {
            foreach ($catches as $catch) {
                $context = $analysis->getContext($catch['function'], $file);

                if ($context === null) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(sprintf(
                    'This catch swallows the cancellation of %s in its try block %s.',
                    $catch['operation'],
                    WorkerStateAnalysis::describeContext($context),
                ))
                    ->tip('Rethrow the exception, or rethrow it when Coroutine::causedByCancellation() is true, so the canceled work stops.')
                    ->identifier('hypervel.cancellation.swallowed')
                    ->file($file)
                    ->line($catch['line'])
                    ->build();
            }
        }

        // Reused errors stay valid only while the booted application and the classes the lifetimes
        // were read from are unchanged.
        $analysis->track($scope);

        return $errors;
    }
}
