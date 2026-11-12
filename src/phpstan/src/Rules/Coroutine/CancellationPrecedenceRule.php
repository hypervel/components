<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\Printer\ExprPrinter;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use Swoole\Coroutine\CanceledException;

use function array_push;
use function count;
use function is_string;
use function sprintf;

/**
 * Reports caught exceptions kept with ??=, which loses a later failure's cancellation.
 *
 * @implements Rule<TryCatch>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkCancellationPrecedence%')]
class CancellationPrecedenceRule implements Rule
{
    /**
     * The statements that leave a guard's cancellation branch, so the statements after the guard see only ordinary failures.
     */
    private const array EXITS = [Throw_::class, Return_::class, Continue_::class, Break_::class];

    /**
     * Create a new cancellation precedence rule instance.
     */
    public function __construct(
        private CoroutineRuleHelper $coroutineRuleHelper,
        private ExprPrinter $exprPrinter,
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
     * Report ??= assignments that may keep an ordinary failure over a caught exception.
     *
     * @param TryCatch $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->catches as $catch) {
            if ($catch->var === null || ! is_string($catch->var->name)) {
                continue;
            }

            $variable = $catch->var->name;

            foreach ($this->findAssignments($catch->stmts, $variable) as $assignment) {
                if ($this->keepsNothingButCancellation($assignment, $node, $catch, $scope)) {
                    continue;
                }

                $kept = $this->exprPrinter->printExpr($assignment->var);

                $errors[] = RuleErrorBuilder::message(sprintf(
                    '%1$s ??= $%2$s keeps the first failure even when a later one carries this coroutine\'s cancellation. Use %1$s = Coroutine::preferCancellation(%1$s, $%2$s).',
                    $kept,
                    $variable,
                ))
                    ->line($assignment->getStartLine())
                    ->identifier('hypervel.cancellation.precedence')
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * Find the ??= assignments of the caught exception that can run when it carries cancellation.
     *
     * The ordinary-failure branches of a top-level Coroutine::causedByCancellation() guard, and the statements
     * after a guard whose cancellation branch exits, never see a cancellation.
     *
     * @param array<Stmt> $statements
     *
     * @return list<Coalesce>
     */
    private function findAssignments(array $statements, string $variable): array
    {
        $reachable = [];

        foreach ($statements as $statement) {
            if (! $statement instanceof If_
                || ($polarity = $this->coroutineRuleHelper->guardPolarity($statement->cond, $variable)) === null) {
                $reachable[] = $statement;

                continue;
            }

            if (! $polarity) {
                foreach ($statement->elseifs as $elseif) {
                    array_push($reachable, ...$elseif->stmts);
                }

                array_push($reachable, ...($statement->else->stmts ?? []));

                continue;
            }

            array_push($reachable, ...$statement->stmts);

            if ($this->coroutineRuleHelper->endsWith($statement->stmts, self::EXITS)) {
                break;
            }
        }

        /** @var list<Coalesce> $assignments */
        $assignments = $this->coroutineRuleHelper->find(
            $reachable,
            static fn (Node $node): bool => $node instanceof Coalesce
                && $node->expr instanceof Variable
                && $node->expr->name === $variable,
            $variable,
        );

        return $assignments;
    }

    /**
     * Determine whether the kept value can only be nothing yet or a cancellation when the assignment runs.
     *
     * The type at the try's start holds at the assignment only for a local variable that the try and catch
     * mention nowhere else, including in nested functions, closure use lists and by-reference arguments.
     */
    private function keepsNothingButCancellation(Coalesce $assignment, TryCatch $node, Catch_ $catch, Scope $scope): bool
    {
        if (! $assignment->var instanceof Variable || ! is_string($assignment->var->name)) {
            return false;
        }

        $name = $assignment->var->name;

        $mentions = (new NodeFinder)->find(
            [...$node->stmts, ...$catch->stmts],
            static fn (Node $node): bool => $node instanceof Variable && $node->name === $name,
        );

        return count($mentions) === 1
            && (new ObjectType(CanceledException::class))
                ->isSuperTypeOf(TypeCombinator::removeNull($scope->getType($assignment->var)))
                ->yes();
    }
}
