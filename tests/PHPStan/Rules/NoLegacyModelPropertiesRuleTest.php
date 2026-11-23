<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoLegacyModelPropertiesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoLegacyModelPropertiesRule> */
class NoLegacyModelPropertiesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoLegacyModelPropertiesRule::class);
    }

    public function testRule(): void
    {
        $subclasses = "Subclasses' own properties and attributes can take precedence differently: review them, and keep the property where attributes cannot express the hierarchy. See https://hypervel.org/docs/static-analysis#model-configuration";
        $tip = "\n    💡 " . $subclasses;
        $mergeTip = static fn (string $attribute): string => "\n    💡 • {$subclasses}\n• A subclass's own #[{$attribute}] replaces this one entirely, so it must repeat the values it inherits.";

        $this->analyse([__DIR__ . '/data/legacy-model-properties.php'], [
            ['Model property LegacyModelProperties\Post::$table should be replaced by attribute #[Table(self::TABLE)].' . $mergeTip('Table'), 23],
            ['Model property LegacyModelProperties\Post::$connection should be replaced by attribute #[Connection(\LegacyModelProperties\ConnectionName::Reporting)].' . $tip, 25],
            ["Model property LegacyModelProperties\\Post::\$fillable should be replaced by attribute #[Fillable(['title', 'body'])]." . $mergeTip('Fillable'), 27],
            ['Model property LegacyModelProperties\Post::$guarded should be replaced by attribute #[Unguarded].' . $tip, 29],
            ['Model property LegacyModelProperties\Post::$timestamps should be replaced by attribute #[WithoutTimestamps].' . $tip, 31],
            ["Model property LegacyModelProperties\\Post::\$dateFormat should be replaced by attribute #[DateFormat('U')]." . $tip, 33],
            ["Model property LegacyModelProperties\\Post::\$touches should be replaced by attribute #[Touches(['author'])]." . $mergeTip('Touches'), 35],
            ['Model property LegacyModelProperties\Post::$primaryKey should be removed: the model resolves the same value without it.' . $tip, 37],
            ["Model property LegacyModelProperties\\Token::\$primaryKey should be replaced by attribute #[Table(key: 'uuid', keyType: 'string')]." . $mergeTip('Table'), 42],
            ["Model property LegacyModelProperties\\Token::\$keyType should be replaced by attribute #[Table(key: 'uuid', keyType: 'string')]." . $mergeTip('Table'), 44],
            ['Model property LegacyModelProperties\Token::$incrementing should be replaced by attribute #[WithoutIncrementing].' . $tip, 46],
            ['Model property LegacyModelProperties\SequencedToken::$incrementing should be replaced by attribute #[Table(incrementing: true)].' . $mergeTip('Table'), 51],
            ["Model property LegacyModelProperties\\Invoice::\$primaryKey should be replaced by attribute #[Table('invoices', key: 'number')]." . $mergeTip('Table'), 57],
            ["Model property LegacyModelProperties\\Invoice::\$guarded should be replaced by attribute #[Guarded(['number'])]." . $tip, 59],
            ['Model property LegacyModelProperties\LedgerEntry::$incrementing should be removed: the model resolves the same value without it.' . $tip, 70],
            ["Model property LegacyModelProperties\\AdminAccount::\$hidden should be replaced by attribute #[Hidden(['token', 'password'])]." . $mergeTip('Hidden'), 80],
            ["Model property LegacyModelProperties\\BaseUser::\$hidden should be replaced by attribute #[Hidden(['password'])]." . $mergeTip('Hidden'), 85],
            ['Model property LegacyModelProperties\BaseUser::$timestamps should be replaced by attribute #[WithoutTimestamps].' . $tip, 87],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
