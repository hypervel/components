<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use PhpParser\Node;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Reports instanceof CanceledException checks, which miss cancellation carried by another exception.
 *
 * @implements Rule<Instanceof_>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkCancellationHandling%')]
class ExactCancellationCheckRule implements Rule
{
    /**
     * Create a new exact cancellation check rule instance.
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
        return Instanceof_::class;
    }

    /**
     * Report exact cancellation checks of values that may be exceptions but are not already known to be cancellations.
     *
     * @param Instanceof_ $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Name || ! $this->coroutineRuleHelper->isCancellation($node->class)) {
            return [];
        }

        $subjectType = $scope->getType($node->expr);
        $throwableType = new ObjectType(Throwable::class);

        if ($throwableType->isSuperTypeOf(TypeCombinator::removeNull($subjectType))->no()
            || (new ObjectType(CanceledException::class))->isSuperTypeOf($subjectType)->yes()) {
            return [];
        }

        $error = RuleErrorBuilder::message('Checking instanceof CanceledException misses cancellation carried by another exception. Use Coroutine::causedByCancellation(), or Coroutine::preferCancellation() to choose between failures.')
            ->identifier('hypervel.cancellation.exactType')
            ->addTip('A cleanup or callback failure can carry the cancellation as its previous exception.');

        if (! $throwableType->isSuperTypeOf($subjectType)->yes()) {
            $error->addTip('Coroutine::causedByCancellation() takes a Throwable, so check "instanceof Throwable" first for a value that may be null or not an exception.');
        }

        return [$error->build()];
    }
}
