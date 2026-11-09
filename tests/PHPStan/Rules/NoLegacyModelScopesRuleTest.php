<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoLegacyModelScopesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoLegacyModelScopesRule> */
class NoLegacyModelScopesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoLegacyModelScopesRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/model-scope-methods.php'], [
            ["Local query scope method 'scopeOwnedBy' should be declared as protected #[Scope] method 'ownedBy'.", 22],
            ["Local query scope method 'scopeActive' should be declared as protected #[Scope] method 'active'.", 32],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
