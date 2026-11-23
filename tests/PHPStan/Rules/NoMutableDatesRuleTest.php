<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoMutableDatesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoMutableDatesRule> */
class NoMutableDatesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoMutableDatesRule::class);
    }

    public function testRule(): void
    {
        $error = static fn (string $subject, int $line): array => [
            $subject . ' creates a mutable date.',
            $line,
            'Create dates with the Date facade, the now() and today() helpers, or CarbonImmutable.',
        ];

        $this->analyse([__DIR__ . '/data/mutable-dates.php'], [
            $error('new Hypervel\Support\Carbon', 25),
            $error('new Carbon\Carbon', 26),
            $error('Hypervel\Support\Carbon::now()', 27),
            $error('Carbon\Carbon::parse()', 28),
            $error('Hypervel\Support\Carbon::createFromFormat()', 29),
            $error('Hypervel\Support\Carbon::make()', 30),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
