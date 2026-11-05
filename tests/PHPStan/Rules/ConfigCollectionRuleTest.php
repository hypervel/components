<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\ConfigCollectionRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ConfigCollectionRule> */
class ConfigCollectionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ConfigCollectionRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/config-collection-rule.php'], [
            [
                "Config key 'test.foo' is not an array.",
                12,
            ],
            [
                "Config key 'test.foo' is not an array.",
                19,
            ],
            [
                "Config key 'test.foo' is not an array.",
                20,
            ],
            [
                "Config key 'test.missing' is not an array.",
                26,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../Type/data/config-with-config-paths.neon',
        ];
    }
}
