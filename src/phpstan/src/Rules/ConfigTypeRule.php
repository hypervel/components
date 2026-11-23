<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Internal\ConfigHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports typed config getters, such as Config::integer(), whose value never has the
 * getter's type, so the call always throws.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkConfigTypes%')]
class ConfigTypeRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private ConfigHelper $configHelper)
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
     * Report typed getter calls for config values that never have the getter's type.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $methodName = $this->configHelper->getMethodName($node, $scope);

        if ($methodName === null || $methodName === 'get' || $methodName === 'collection') {
            return [];
        }

        $message = $this->configHelper->getTypeError($node, $scope, $methodName);

        return $message === null ? [] : [
            RuleErrorBuilder::message($message)
                ->identifier('hypervel.configType')
                ->build(),
        ];
    }
}
