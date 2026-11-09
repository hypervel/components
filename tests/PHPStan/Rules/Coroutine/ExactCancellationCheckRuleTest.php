<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\ExactCancellationCheckRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ExactCancellationCheckRule> */
class ExactCancellationCheckRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Checking instanceof CanceledException misses cancellation carried by another exception. Use Coroutine::causedByCancellation(), or Coroutine::preferCancellation() to choose between failures.';

    private const string CARRIED_TIP = 'A cleanup or callback failure can carry the cancellation as its previous exception.';

    private const string GUARD_TIP = 'Coroutine::causedByCancellation() takes a Throwable, so check "instanceof Throwable" first for a value that may be null or not an exception.';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ExactCancellationCheckRule::class);
    }

    public function testRule(): void
    {
        $guarded = self::MESSAGE . "\n    💡 • " . self::CARRIED_TIP . "\n• " . self::GUARD_TIP;

        $this->analyse([__DIR__ . '/data/exact-cancellation-checks.php'], [
            [self::MESSAGE . "\n    💡 " . self::CARRIED_TIP, 14],
            [$guarded, 19],
            [$guarded, 24],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
