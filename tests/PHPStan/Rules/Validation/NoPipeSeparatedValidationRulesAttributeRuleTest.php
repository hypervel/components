<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation;

use Hypervel\PHPStan\Rules\Validation\NoPipeSeparatedValidationRulesAttributeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoPipeSeparatedValidationRulesAttributeRule> */
class NoPipeSeparatedValidationRulesAttributeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoPipeSeparatedValidationRulesAttributeRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/pipe-separated-rule-attributes.php'], [
            [NoPipeSeparatedValidationRulesRuleTest::fixable('required|email'), 12],
            [NoPipeSeparatedValidationRulesRuleTest::fixable('required|string'), 16],
            [NoPipeSeparatedValidationRulesRuleTest::fixable('max:5|min:1'), 18],
            [NoPipeSeparatedValidationRulesRuleTest::fixable('required|string'), 20],
        ]);
    }

    public function testFix(): void
    {
        $this->fix(__DIR__ . '/data/pipe-separated-rule-attributes.php', __DIR__ . '/data/pipe-separated-rule-attributes-fixed.php');
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
