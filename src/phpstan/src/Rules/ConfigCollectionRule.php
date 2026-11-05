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
use PHPStan\Type\VerbosityLevel;

use function sprintf;

/**
 * Upstream checks only Config::collection(); repository calls, including config()->collection(),
 * are checked too.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkConfigTypes%')]
final class ConfigCollectionRule implements Rule
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
     * Report collection() calls for config values that are never arrays.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->configHelper->getMethodName($node, $scope) !== 'collection') {
            return [];
        }

        $key = $node->getArg('key', 0);
        $valueType = $this->configHelper->getValueType($node, $scope);

        if ($key === null || $valueType === null || ! $valueType->isArray()->no()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Config key %s is not an array, so collection() throws. Correct the config value or use the getter that matches its type.',
                $scope->getType($key->value)->describe(VerbosityLevel::value()),
            ))
                ->identifier('hypervel.configCollection')
                ->build(),
        ];
    }
}
