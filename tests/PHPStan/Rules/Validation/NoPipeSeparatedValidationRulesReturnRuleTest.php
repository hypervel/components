<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation;

use Hypervel\PHPStan\Rules\Validation\NoPipeSeparatedValidationRulesReturnRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoPipeSeparatedValidationRulesReturnRule> */
class NoPipeSeparatedValidationRulesReturnRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoPipeSeparatedValidationRulesReturnRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/pipe-separated-rules-methods.php'], [
            [NoPipeSeparatedValidationRulesRuleTest::fixable('sometimes|string'), 16],
            [NoPipeSeparatedValidationRulesRuleTest::fixable('required|string|max:255'), 22],
            [NoPipeSeparatedValidationRulesRuleTest::unfixable('regex:/^(a|b)$/') . NoPipeSeparatedValidationRulesRuleTest::REGEX_TIP, 24],
            [NoPipeSeparatedValidationRulesRuleTest::fixable('required|string'), 44],
            [NoPipeSeparatedValidationRulesRuleTest::fixable('required|min:1'), 45],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
