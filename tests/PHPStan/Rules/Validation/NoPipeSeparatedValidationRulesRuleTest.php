<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation;

use Hypervel\PHPStan\Rules\Validation\NoPipeSeparatedValidationRulesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoPipeSeparatedValidationRulesRule> */
class NoPipeSeparatedValidationRulesRuleTest extends RuleTestCase
{
    public const string DYNAMIC = 'Validation rules are pipe-separated; list them in an array.';

    public const string REGEX_TIP = "\n    💡 A regex containing \"|\" must be its own array element.";

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoPipeSeparatedValidationRulesRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/pipe-separated-calls.php'], [
            [self::fixable('required|string'), 35],
            [self::fixable('required|string'), 36],
            [self::fixable('required|email'), 37],
            [self::fixable('required|max:255'), 38],
            [self::fixable('required|max:255'), 39],
            [self::fixable('required|string'), 40],
            [self::fixable('required|string'), 41],
            [self::fixable('required|string'), 42],
            [self::fixable('required|string'), 43],
            [self::fixable('required|string'), 44],
            [self::fixable('required|string'), 45],
            [self::fixable('required|max:500'), 46],
            [self::fixable('required|string'), 47],
            [self::fixable('required|string'), 50],
            [self::DYNAMIC, 51],
            [self::DYNAMIC, 52],
            [self::DYNAMIC, 53],
            [self::unfixable('required|regex:/^(a|b)$/') . self::REGEX_TIP, 54],
            [self::unfixable('regex:/^(a|b)$/') . self::REGEX_TIP, 55],
            [self::unfixable('required | string'), 56],
            [self::unfixable('required|'), 57],
            [self::DYNAMIC, 58],
            [self::fixable('required|string'), 64],
            [self::fixable('nullable|string'), 64],
            [self::fixable('nullable|string'), 65],
            [self::fixable('required|string'), 66],
            [self::fixable('required|string'), 67],
            [self::fixable('required|string'), 67],
            [self::fixable('required|email'), 68],
            [self::fixable('required|string'), 73],
            [self::fixable('required|string'), 74],
            [self::fixable('required|max:255'), 79],
            [self::fixable('required|max:255'), 80],
            [self::fixable('required|min:3'), 81],
            [self::fixable('required|max:5'), 82],
            [self::fixable('required|string'), 87],
            [self::fixable('max:5|min:1'), 87],
            [self::DYNAMIC, 88],
            [self::unfixable('required|regex:/^(a|b)$/') . self::REGEX_TIP, 89],
        ]);
    }

    public function testCorrectRules(): void
    {
        // The attribute rule reports #[Rule], so these calls never see it again.
        $this->analyse([__DIR__ . '/data/correct-validation-rules.php', __DIR__ . '/data/pipe-separated-rule-attributes.php'], []);
    }

    public function testFix(): void
    {
        $this->fix(__DIR__ . '/data/pipe-separated-calls.php', __DIR__ . '/data/pipe-separated-calls-fixed.php');
    }

    /**
     * Get the message for rules with an unambiguous array replacement.
     */
    public static function fixable(string $rules): string
    {
        $segments = implode(', ', array_map(static fn (string $segment): string => "'{$segment}'", explode('|', $rules)));

        return "Validation rules '{$rules}' are pipe-separated; list them in an array: [{$segments}].";
    }

    /**
     * Get the message for rules whose split is ambiguous.
     */
    public static function unfixable(string $rules): string
    {
        return "Validation rules '{$rules}' are pipe-separated; list them in an array.";
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
