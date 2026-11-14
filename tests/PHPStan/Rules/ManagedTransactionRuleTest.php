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
        $generator = static fn (int $line): array => [
            'The transaction() callback is a generator, so none of its body runs until after transaction() commits.',
            $line,
            'Run the database work in the callback itself, and return the results instead of yielding them.',
        ];

        $this->analyse([__DIR__ . '/data/managed-transactions.php'], [
            $endedEarly('commit', 62),
            $endedEarly('rollBack', 64),
            $endedEarly('rollBack', 66),
            $endedEarly('commit', 69),
            $endedEarly('commit', 74),
            $endedEarly('commit', 78),
            $rolledBackToZero(86),
            $rolledBackToZero(89),
            $endedEarly('commit', 146),
            $endedEarly('commit', 165),
            $endedEarly('rollBack', 167),
            $generator(197),
            $generator(200),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
