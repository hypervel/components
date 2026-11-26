<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\SchemaModifierExistenceRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<SchemaModifierExistenceRule> */
class SchemaModifierExistenceRuleTest extends RuleTestCase
{
    private bool $reportMagicMethods = true;

    protected function getRule(): Rule
    {
        return new SchemaModifierExistenceRule($this->reportMagicMethods);
    }

    public function testUnknownModifiers(): void
    {
        $tip = 'The schema grammars ignore unknown modifiers.';

        $this->analyse([__DIR__ . '/data/schema-modifiers.php'], [
            ['Call to an undefined method Hypervel\Database\Schema\ColumnDefinition::nulable().', 14, $tip],
            ['Call to an undefined method Hypervel\Database\Schema\IndexDefinition::algoritm().', 15, $tip],
            ['Call to an undefined method Hypervel\Database\Schema\ForeignKeyDefinition::onDelet().', 16, $tip],
            ['Call to an undefined method Hypervel\Database\Schema\ForeignIdColumnDefinition::tabel().', 17, $tip],
            ['Call to an undefined method Hypervel\Database\Schema\ColumnDefinition|Hypervel\Database\Schema\IndexDefinition::nulable().', 18, $tip],
            ['Call to an undefined method Hypervel\Database\Schema\ColumnDefinition::extensionFlag().', 37, $tip],
        ]);
    }

    public function testUnknownModifiersWithoutMagicMethodReporting(): void
    {
        $this->reportMagicMethods = false;

        $this->analyse([__DIR__ . '/data/schema-modifiers.php'], []);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
