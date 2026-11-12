<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Collectors\UnusableSchemaDumpCollector;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\PHPStan\Rules\UnusableSchemaDumpRule;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<UnusableSchemaDumpRule> */
class UnusableSchemaDumpRuleTest extends RuleTestCase
{
    private const string TIP = 'Fix the dump or regenerate it with schema:dump. Until then, the columns it defines are unknown.';

    protected function getRule(): Rule
    {
        return new UnusableSchemaDumpRule;
    }

    /** @return array<Collector<Node, mixed>> */
    protected function getCollectors(): array
    {
        return [new UnusableSchemaDumpCollector(self::getContainer()->getByType(ModelPropertyHelper::class))];
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/unusable-schema-dump.php'], [
            ['Model property types cannot use this schema dump: Unterminated string literal at SQL line 3.', 3, self::TIP],
            ['Model property types cannot use this schema dump: PostgreSQL archives must be exported as plain SQL before parsing.', 1, self::TIP],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/unusable-schema-dumps.neon'];
    }
}
