<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\ExactCancellationCatchRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function sprintf;

/** @extends RuleTestCase<ExactCancellationCatchRule> */
class ExactCancellationCatchRuleTest extends RuleTestCase
{
    private const string MESSAGE = "Catching CanceledException misses cancellation carried by another exception, which the catch on line %d handles as an ordinary failure. Check Coroutine::causedByCancellation() in that catch instead.\n    💡 A cleanup or callback failure in the try block can carry the cancellation as its previous exception.";

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ExactCancellationCatchRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/exact-cancellation-catches.php'], [
            [sprintf(self::MESSAGE, 22), 20],
            [sprintf(self::MESSAGE, 37), 35],
            [sprintf(self::MESSAGE, 54), 52],
            [sprintf(self::MESSAGE, 74), 72],
            [sprintf(self::MESSAGE, 85), 83],
            [sprintf(self::MESSAGE, 169), 167],
            [sprintf(self::MESSAGE, 220), 218],
            [sprintf(self::MESSAGE, 235), 233],
            [sprintf(self::MESSAGE, 248), 246],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
