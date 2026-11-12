<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\ManagedTransactionRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ManagedTransactionRule> */
class ManagedTransactionRuleTest extends RuleTestCase
{
    private const string TIP = 'Let transaction() commit when the callback returns, or throw an exception to roll it back.';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ManagedTransactionRule::class);
    }

    public function testRule(): void
    {
        $endedEarly = static fn (string $method, int $line): array => [
            $method . '() ends the transaction that transaction() manages before its callback returns.',
            $line,
            self::TIP,
        ];
        $rolledBackToZero = static fn (int $line): array => [
            'rollBack(0) rolls back the transaction that transaction() manages, with every enclosing one.',
            $line,
            self::TIP,
        ];

        $this->analyse([__DIR__ . '/data/managed-transactions.php'], [
            $endedEarly('commit', 58),
            $endedEarly('rollBack', 60),
            $endedEarly('rollBack', 62),
            $endedEarly('commit', 65),
            $endedEarly('commit', 70),
            $endedEarly('commit', 74),
            $rolledBackToZero(82),
            $rolledBackToZero(85),
            $endedEarly('commit', 142),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
