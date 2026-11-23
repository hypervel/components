<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\NoCoroutinesInTransactionsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoCoroutinesInTransactionsRule> */
class ConfiguredConcurrencyDriverTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoCoroutinesInTransactionsRule::class);
    }

    public function testConfiguredCoroutineDriverStartsCoroutines(): void
    {
        $this->analyse([__DIR__ . '/data/concurrency-run.php'], [
            [NoCoroutinesInTransactionsRuleTest::MESSAGE, 15],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/configured-concurrency.neon'];
    }
}
