<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use Hypervel\Queue\Middleware\SkipIfBatchCancelled;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Analyser\StatementContext;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

use function array_all;
use function in_array;
use function sprintf;

/**
 * A queued job that uses the `Batchable` trait must respect early batch
 * cancellation, either by checking `$this->batch()?->cancelled()` at the start of
 * `handle()`, or by registering the `SkipIfBatchCancelled` middleware from
 * `middleware()`.
 *
 * Cancelling a batch (`$batch->cancel()`, or the automatic cancel on first
 * failure when the batch is not `allowFailures`) only stops *future* dispatches
 * from running their body. Hypervel does not forcibly kill jobs already on the
 * queue: each still wakes up and, unless it checks `cancelled()`, runs its full
 * body. That is wasted work at best, and at worst it keeps mutating state
 * (writing files, calling external APIs, charging cards) for a batch the caller
 * has already abandoned.
 *
 * Each concrete job is checked using its effective methods, including methods
 * inherited from parents and traits. Overridden methods do not supply a guard.
 * Unlike upstream, which accepts any method named `cancelled()`, a check is
 * recognized by type: the batch's `cancelled()` or `canceled()`, the job's
 * `batching()`, or the middleware class. The rule reports a missing recognized
 * check, not proof that a canceled batch does no work.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkBatchableJobChecksCancellation%')]
class BatchableJobChecksCancellationRule implements Rule
{
    use InspectsQueuedJobs;

    /**
     * Create a new rule instance.
     */
    public function __construct(
        #[AutowiredParameter(ref: '@cachedRichParser')]
        private Parser $parser,
        private ScopeFactory $scopeFactory,
        private NodeScopeResolver $nodeScopeResolver,
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report a batchable queued job without a recognized cancellation check.
     *
     * @param InClassNode $node
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! $this->isDispatchableClass($classReflection)) {
            return [];
        }

        if (! $classReflection->is(ShouldQueue::class)) {
            return [];
        }

        if (! $this->usesTrait($classReflection, Batchable::class)) {
            return [];
        }

        if ($this->guardsCancellation($classReflection, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'No batch cancellation check was found in batchable job %s.',
                $classReflection->getDisplayName(),
            ))
                ->tip('Check $this->batch()?->cancelled() or $this->batching(), or return the SkipIfBatchCancelled middleware from middleware().')
                ->identifier('hypervel.batchableJobChecksCancellation')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * Determine whether one of the job's effective methods checks for batch cancellation.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function guardsCancellation(ClassReflection $classReflection, Scope $scope): bool
    {
        $finder = new NodeFinder;
        $batchableFile = $this->reflectionProvider->getClass(Batchable::class)->getFileName();

        // The method nodes of each file, by line span, so each file is searched once. They are
        // not kept between jobs, which would hold the files' trees beyond PHPStan's parser cache.
        $methodNodes = [];

        foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
            $fileName = $method->getFileName();

            // Batchable::batching() checks cancellation itself, but merely using
            // the trait does not mean the job calls that helper.
            if ($fileName === false || $fileName === $batchableFile) {
                continue;
            }

            if (! isset($methodNodes[$fileName])) {
                $methodNodes[$fileName] = [];

                foreach ($finder->findInstanceOf($this->parser->parseFile($fileName), ClassLike::class) as $candidate) {
                    foreach ($candidate->getMethods() as $candidateMethod) {
                        $methodNodes[$fileName][$candidateMethod->getStartLine() . ':' . $candidateMethod->getEndLine()] = [$candidate, $candidateMethod];
                    }
                }
            }

            [$classLike, $methodNode] = $methodNodes[$fileName][$method->getStartLine() . ':' . $method->getEndLine()] ?? [null, null];

            if ($classLike?->namespacedName === null || $methodNode === null) {
                continue;
            }

            // A method body is not part of the declarations whose changes reanalyze the job.
            if ($fileName !== $scope->getFile()) {
                $scope->trackFileDependency($fileName);
            }

            $middleware = $finder->findFirst(
                $methodNode,
                fn (Node $node): bool => ($node instanceof New_ || ($node instanceof ClassConstFetch && $node->name instanceof Identifier && $node->name->toLowerString() === 'class'))
                    && $node->class instanceof Name
                    && ! $node->class->isSpecialClassName()
                    && $this->reflectionProvider->hasClass($node->class->toString())
                    && $this->reflectionProvider->getClass($node->class->toString())->is(SkipIfBatchCancelled::class),
            );

            if ($middleware !== null) {
                return true;
            }

            // Only a method calling one of the check methods needs its types.
            $checkCall = $finder->findFirst(
                $methodNode,
                static fn (Node $node): bool => ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                    && $node->name instanceof Identifier
                    && in_array($node->name->toLowerString(), ['cancelled', 'canceled', 'batching'], true),
            );

            if ($checkCall !== null && $this->callsCancellationCheck($classLike, $methodNode, $method->getDeclaringClass()->getName(), $fileName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the method calls the batch's cancellation check or the job's batching().
     *
     * The method is analyzed in its declaring class, and in its trait, as PHPStan analyzes it.
     */
    private function callsCancellationCheck(ClassLike $classLike, ClassMethod $methodNode, string $declaringClass, string $fileName): bool
    {
        $methodScope = $this->scopeFactory->create(ScopeContext::create($fileName));
        $namespace = $classLike->namespacedName?->slice(0, -1);

        if ($namespace !== null) {
            $methodScope = $methodScope->enterNamespace($namespace->toString());
        }

        $methodScope = $methodScope->enterClass($this->reflectionProvider->getClass($declaringClass));

        if ($classLike instanceof Trait_ && $classLike->namespacedName !== null) {
            // @phpstan-ignore phpstanApi.method (PHPStan enters a trait the same way when it analyzes the trait's methods.)
            $methodScope = $methodScope->enterTrait($this->reflectionProvider->getClass($classLike->namespacedName->toString()));
        }

        $checks = false;

        $this->nodeScopeResolver->processStmtNodes(
            $classLike,
            [$methodNode],
            $methodScope,
            function (Node $node, Scope $scope) use (&$checks): void {
                // PHPStan also processes a nullsafe call as a MethodCall on the non-null receiver.
                if ($checks || ! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
                    return;
                }

                $receiverType = TypeCombinator::removeNull($scope->getType($node->var));

                $checks = match ($node->name->toLowerString()) {
                    'cancelled', 'canceled' => (new ObjectType(Batch::class))->isSuperTypeOf($receiverType)->yes(),
                    'batching' => $receiverType->getObjectClassReflections() !== []
                        && array_all(
                            $receiverType->getObjectClassReflections(),
                            fn (ClassReflection $receiver): bool => $this->usesTrait($receiver, Batchable::class),
                        ),
                    default => false,
                };
            },
            StatementContext::createTopLevel(),
        );

        return $checks;
    }
}
