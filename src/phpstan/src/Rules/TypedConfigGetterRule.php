<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Internal\ConfigHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * Reports a config read whose value always has one type that a typed getter returns.
 *
 * Only an application's configuration is known: an application using a package can
 * give the keys the package reads other values.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkTypedConfigGetters%')]
class TypedConfigGetterRule implements Rule
{
    /**
     * Create a new typed config getter rule instance.
     */
    public function __construct(
        private ConfigHelper $configHelper,
        #[AutowiredParameter(ref: '%noUntypedConfigReads%')]
        private bool $noUntypedConfigReads,
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
     * Report an untyped read of a config value with one type.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->noUntypedConfigReads || $node->isFirstClassCallable()) {
            return [];
        }

        $recommendation = $this->configHelper->recommendedGetter($node, $scope);

        if ($recommendation === null) {
            return [];
        }

        [$key, $getter, $description] = $recommendation;

        return [
            RuleErrorBuilder::message(sprintf(
                'Config key %s always holds %s, so read it with %s().',
                $key,
                $description,
                $getter,
            ))
                ->identifier('hypervel.typedConfigGetter')
                ->tip(sprintf(
                    'Use config()->%1$s(), Config::%1$s() or the repository\'s %1$s(), which return the value\'s type and throw if the configuration changes it.',
                    $getter,
                ))
                ->build(),
        ];
    }
}
