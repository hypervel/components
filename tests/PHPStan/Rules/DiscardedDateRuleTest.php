<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\DiscardedDateRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DiscardedDateRule> */
class DiscardedDateRuleTest extends RuleTestCase
{
    private const string TIP = "Immutable date methods return a new date, so assign the result where it's needed.";

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(DiscardedDateRule::class);
    }

    public function testRule(): void
    {
        $unchanged = static fn (string $method, int $line): array => [
            'The result of ' . $method . '() is discarded, so the immutable date is unchanged.',
            $line,
            self::TIP,
        ];
        $mayChange = static fn (string $method, int $line): array => [
            'The result of ' . $method . '() is discarded, so any change it makes to an immutable date is lost.',
            $line,
            self::TIP,
        ];

        $this->analyse([__DIR__ . '/data/discarded-dates.php'], [
            $unchanged('addDay', 28),
            $unchanged('subMonths', 29),
            $unchanged('startOfDay', 30),
            $unchanged('endOfMonth', 31),
            $unchanged('next', 32),
            $unchanged('previousWeekday', 33),
            $unchanged('nthOfMonth', 34),
            $unchanged('firstOfQuarter', 35),
            $unchanged('lastOfYear', 36),
            $unchanged('roundHour', 37),
            $unchanged('floorDay', 38),
            $unchanged('ceilMinute', 39),
            $unchanged('modify', 40),
            $unchanged('setTimezone', 41),
            $unchanged('year', 42),
            $unchanged('setMicroseconds', 43),
            $unchanged('addDays', 44),
            $unchanged('plus', 45),
            $unchanged('utc', 46),
            $unchanged('startOfDay', 47),
            $unchanged('dayOfYear', 56),
            $unchanged('dayOfYear', 57),
            $unchanged('dayOfWeek', 58),
            $unchanged('tz', 59),
            $mayChange('dayOfYear', 60),
            $mayChange('week', 61),
            $mayChange('addDay', 70),
            $mayChange('dayOfYear', 71),
            $mayChange('setTime', 72),
            $unchanged('subDay', 86),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
