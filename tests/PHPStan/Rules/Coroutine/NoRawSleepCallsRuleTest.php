<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\NoRawSleepCallsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoRawSleepCallsRule> */
class NoRawSleepCallsRuleTest extends RuleTestCase
{
    private const string TIP = "\n    💡 Keep the native call only where real time must pass, such as polling an external process.";

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoRawSleepCallsRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/raw-sleep.php'], [
            ['Call Sleep::sleep() instead of sleep(), so tests can fake the delay.' . self::TIP, 14],
            ['Call Sleep::usleep() instead of usleep(), so tests can fake the delay.' . self::TIP, 15],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
