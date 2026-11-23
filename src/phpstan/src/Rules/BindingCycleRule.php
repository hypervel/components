<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Container\Container;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Facades\App;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
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

use function class_basename;
use function count;
use function sprintf;

/**
 * Reports a binding whose concrete class name is an alias of its own abstract.
 *
 * A binding to another key resolves that key, and resolution follows its aliases, so a concrete
 * that aliases back to the abstract resolves the binding again without end.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkBindingCycles%')]
class BindingCycleRule implements Rule
{
    use HasContainer;

    /**
     * The lowercase names of the binding methods checked. The *If() variants are skipped: the booted
     * state can't show whether a call installed its binding or found one already registered.
     */
    private const array METHODS = ['bind' => true, 'singleton' => true, 'scoped' => true];

    /**
     * Create a new binding cycle rule instance.
     */
    public function __construct(
        private RuntimeInputs $runtimeInputs,
        private WorkerStateHelper $workerStateHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report a binding that resolves itself.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof MethodCall || $node instanceof StaticCall)
            || ! $node->name instanceof Identifier
            || ! isset(self::METHODS[$node->name->toLowerString()])) {
            return [];
        }

        $abstract = $node->getArg('abstract', 0);
        $concrete = $node->getArg('concrete', 1);

        if ($abstract === null || $concrete === null || ! $this->bindsOnApplication($node, $scope)) {
            return [];
        }

        $abstracts = $scope->getType($abstract->value)->getConstantStrings();
        $concretes = $scope->getType($concrete->value)->getConstantStrings();

        if (count($abstracts) !== 1 || count($concretes) !== 1 || $abstracts[0]->getValue() === $concretes[0]->getValue()) {
            return [];
        }

        // The booted aliases decide where the concrete resolves.
        $this->runtimeInputs->track($scope);

        // The analysis always runs on the framework container: the booted application or a test's own.
        /** @var Container $container */
        $container = $this->getContainer();

        if ($container->getAlias($concretes[0]->getValue()) !== $abstracts[0]->getValue()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "%s() binds '%s' to %s, which is an alias of '%s', so resolving it never ends.",
                $node->name->toString(),
                $abstracts[0]->getValue(),
                $concretes[0]->getValue(),
                $abstracts[0]->getValue(),
            ))
                ->identifier('hypervel.bindingCycle')
                ->tip(sprintf('Bind a closure that creates %s with new instead of its class name.', class_basename($concretes[0]->getValue())))
                ->build(),
        ];
    }

    /**
     * Determine whether the call runs the framework's binding method on the application container.
     */
    private function bindsOnApplication(MethodCall|StaticCall $call, Scope $scope): bool
    {
        if ($call instanceof MethodCall) {
            return $this->workerStateHelper->isApplicationContainerCall($call, $scope);
        }

        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return false;
        }

        $facade = new ObjectType($scope->resolveName($call->class));

        // A facade subclass's own method is an override, whose body is unknown.
        return (new ObjectType(App::class))->isSuperTypeOf($facade)->yes()
            && $scope->getMethodReflection($facade, $call->name->toString())?->getDeclaringClass()->getName() === App::class;
    }
}
