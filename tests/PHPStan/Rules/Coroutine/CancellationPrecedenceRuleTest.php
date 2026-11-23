<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\CancellationPrecedenceRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function sprintf;

/** @extends RuleTestCase<CancellationPrecedenceRule> */
class CancellationPrecedenceRuleTest extends RuleTestCase
{
    private const string MESSAGE = '%1$s ??= $exception keeps the first failure even when a later one carries this coroutine\'s cancellation. Use %1$s = Coroutine::preferCancellation(%1$s, $exception).';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(CancellationPrecedenceRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/cancellation-precedence.php'], [
            [sprintf(self::MESSAGE, '$failure'), 26],
            [sprintf(self::MESSAGE, '$failure'), 41],
            [sprintf(self::MESSAGE, '$this->failure'), 54],
            [sprintf(self::MESSAGE, '$failure'), 73],
            [sprintf(self::MESSAGE, '$failure'), 146],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
