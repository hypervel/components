<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\SwooleTableInProviderRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<SwooleTableInProviderRule> */
class SwooleTableInProviderRuleTest extends RuleTestCase
{
    private const string TIP = 'Create it from a BeforeServerStart listener, so only the server allocates it before starting its workers.';

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(SwooleTableInProviderRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/swoole-tables.php'], [
            ["A Swoole table created in a service provider's register() is allocated by every process that boots the application.", 18, self::TIP],
            ["A Swoole table created in a service provider's boot() is allocated by every process that boots the application.", 29, self::TIP],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
