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
 * Reports typed config getters, such as Config::integer(), whose value never has the
 * getter's type, so the call always throws.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkConfigTypes%')]
class ConfigTypeRule implements Rule
{
    /**
     * The value types the typed getters require, as the error message names them.
     */
    private const array GETTER_TYPES = [
        'string' => 'a string',
        'integer' => 'an integer',
        'float' => 'a float',
        'boolean' => 'a boolean',
        'array' => 'an array',
    ];

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

        if (! isset(self::GETTER_TYPES[$methodName])) {
            return [];
        }

        $key = $node->getArg('key', 0);
        $valueType = $this->configHelper->getValueType($node, $scope);

        if ($key === null || $valueType === null || ! $this->configHelper->getGetterType($methodName)->isSuperTypeOf($valueType)->no()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Config key %s is not %s, so %s() throws. Correct the config value or use the getter that matches its type.',
                $scope->getType($key->value)->describe(VerbosityLevel::precise()),
                self::GETTER_TYPES[$methodName],
                $methodName,
            ))
                ->identifier('hypervel.configType')
                ->build(),
        ];
    }
}
