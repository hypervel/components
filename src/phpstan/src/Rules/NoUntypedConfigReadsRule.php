<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Config\Repository;
use Hypervel\PHPStan\Internal\ConfigHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\Support\Facades\Config;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

use function array_any;
use function in_array;
use function sprintf;

/**
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%noUntypedConfigReads%')]
class NoUntypedConfigReadsRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private ConfigHelper $configHelper, private WorkerStateHelper $workerStateHelper)
    {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report generic configuration reads even when the caller's config values are unknown.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable() || $node->getAttribute('virtualNullsafeMethodCall', false) || ! $this->isUntypedRead($node, $scope)) {
            return [];
        }

        $recommendation = $this->configHelper->recommendedGetter($node, $scope);
        $message = $recommendation === null
            ? 'Read configuration with the typed getter that matches the values this caller accepts.'
            : sprintf('Read config key %s with %s().', $recommendation[0], $recommendation[1]);

        return [
            RuleErrorBuilder::message($message)
                ->identifier('hypervel.untypedConfigRead')
                ->tip('For values no single getter covers or raw values needed for diagnostics, keep the generic read and explain why with a local @phpstan-ignore hypervel.untypedConfigRead (reason).')
                ->build(),
        ];
    }

    /**
     * Determine whether a call reads configuration without checking its type.
     */
    private function isUntypedRead(CallLike $call, Scope $scope): bool
    {
        if ($call instanceof FuncCall) {
            if (! $this->workerStateHelper->isConfigFunction($call, $scope)
                || array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)) {
                return false;
            }

            $key = $call->getArg('key', 0);

            return $key !== null && $scope->getType($key->value)->isString()->yes();
        }

        if ((! $call instanceof MethodCall && ! $call instanceof NullsafeMethodCall && ! $call instanceof StaticCall)
            || ! $call->name instanceof Identifier || ! in_array($call->name->toLowerString(), ['get', 'getmany'], true)) {
            return false;
        }

        if ($call instanceof StaticCall) {
            $receiver = $call->class instanceof Name ? $scope->resolveTypeByName($call->class) : $scope->getType($call->class)->getObjectTypeOrClassStringObjectType();

            return (new ObjectType(Config::class))->isSuperTypeOf($receiver)->yes();
        }

        if ($call->var instanceof Variable && $call->var->name === 'this'
            && $scope->getClassReflection()?->getName() === Repository::class) {
            return false;
        }

        $receiver = $scope->getType($call->var);

        return ! $receiver->isNull()->yes() && $this->workerStateHelper->isConfigRepositoryType($receiver);
    }
}
