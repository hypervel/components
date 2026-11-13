<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Support\ServiceProvider;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Swoole\Table;

use function in_array;
use function sprintf;
use function strtolower;

/**
 * Reports a Swoole table created directly in a service provider's register() or boot().
 *
 * Every process that boots the application runs those methods, so each one would allocate the
 * table. Closures registered there, such as singleton factories and listeners, run later.
 *
 * @implements Rule<New_>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkSwooleTables%')]
class SwooleTableInProviderRule implements Rule
{
    /**
     * Create a new Swoole table in provider rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * Report a table created in a provider's register() or boot() body.
     *
     * @param New_ $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = strtolower($scope->getFunction()?->getName() ?? '');

        if (! $node->class instanceof Name
            || ! in_array($method, ['register', 'boot'], true)
            || $scope->isInAnonymousFunction()
            || $scope->getClassReflection()?->is(ServiceProvider::class) !== true
        ) {
            return [];
        }

        $class = $scope->resolveName($node->class);

        if (! $this->reflectionProvider->hasClass($class) || ! $this->reflectionProvider->getClass($class)->is(Table::class)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('A Swoole table created in a service provider\'s %s() is allocated by every process that boots the application.', $method))
                ->identifier('hypervel.swooleTableInProvider')
                ->tip('Create it from a BeforeServerStart listener, so only the server allocates it before starting its workers.')
                ->build(),
        ];
    }
}
