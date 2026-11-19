<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\RequestDependentBindingRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<RequestDependentBindingRule> */
class RequestDependentBindingRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RequestDependentBindingRule::class);
    }

    public function testRule(): void
    {
        $error = static fn (string $interface, int $line): array => [
            "The #[BindWhen] condition on Hypervel\\Tests\\PHPStan\\Rules\\RequestDependentBindings\\{$interface} contains a read of the current request or user, but a matched condition binds for the worker's lifetime.",
            $line,
            'Conditions must depend only on state set while the application boots. Register a scoped() binding to choose an implementation per request.',
        ];

        $this->analyse([__DIR__ . '/data/request-dependent-bindings.php', __DIR__ . '/data/request-dependent-bindings-local-request.php'], [
            $error('UsesRequestHelper', 20),
            $error('UsesRequestFacade', 25),
            $error('UsesRequestContext', 30),
            $error('UsesAuthFacade', 38),
            $error('UsesGuard', 44),
            $error('ResolvesRequestAlias', 49),
            $error('ResolvesRequestByName', 54),
            $error('ResolvesRequestWithMakeWith', 59),
            $error('UsesConditionMethod', 64),
            $error('UsesConditionFunction', 69),
            $error('ReadsInCallback', 113),
            $error('CapturesContainer', 126),
            $error('NamesRequestContextRead', 150),
            $error('NamesAuthRead', 155),
            $error('UsesConditionConstant', 165),
            $error('SelectsWithOwnMethod', 216),
            $error('SelectsWithParentMethod', 225),
            $error('LocalRequest\UsesHelperFromAnotherNamespace', 12),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
