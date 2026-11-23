<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use PhpParser\Node;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * Reports an exact CanceledException catch backed by a later catch of every exception.
 *
 * A cleanup or callback failure can carry cancellation as its previous exception. The exact
 * catch misses it, so the later catch handles the canceled operation as an ordinary failure.
 *
 * @implements Rule<TryCatch>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkCancellationHandling%')]
class ExactCancellationCatchRule implements Rule
{
    /**
     * Create a new exact cancellation catch rule instance.
     */
    public function __construct(
        private CoroutineRuleHelper $coroutineRuleHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return TryCatch::class;
    }

    /**
     * Report exact cancellation catches that rethrow or keep the cancellation, followed by a catch of every exception.
     *
     * @param TryCatch $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($this->coroutineRuleHelper->findExactCancellationMisses($node, $scope) as [$catch, $broadCatch]) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Catching CanceledException misses cancellation carried by another exception, which the catch on line %d handles as an ordinary failure. Check Coroutine::causedByCancellation() in that catch instead.',
                $broadCatch->getStartLine(),
            ))
                ->line($catch->getStartLine())
                ->identifier('hypervel.cancellation.exactType')
                ->addTip('A cleanup or callback failure in the try block can carry the cancellation as its previous exception.')
                ->build();
        }

        return $errors;
    }
}
