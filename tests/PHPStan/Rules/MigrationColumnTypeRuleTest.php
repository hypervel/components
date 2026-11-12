<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\MigrationColumnTypeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<MigrationColumnTypeRule> */
class MigrationColumnTypeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(MigrationColumnTypeRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/migration-column-types.php'], [
            ['enum() creates a database enum, so adding a value needs a migration.', 25, 'Use a string column with a PHP enum cast.'],
            ['set() creates a database set, so adding a value needs a migration.', 26, 'Store the values in a jsonb() column cast with AsEnumCollection, or in a related table.'],
            ["json() creates a PostgreSQL json column, which can't be indexed or compared like jsonb.", 27, 'Use jsonb() for JSON the database queries, indexes or validates, or text() for a value only read back whole.'],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/migration-column-types.neon'];
    }
}
