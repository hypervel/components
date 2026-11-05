<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\ConfigTypeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ConfigTypeRule> */
class ConfigTypeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ConfigTypeRule::class);
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/config-type-rule.php'], [
            ["Config key 'test.foo' is not an integer, so integer() throws. Correct the config value or use the getter that matches its type.", 14],
            ["Config key 'test.foo' is not an array, so array() throws. Correct the config value or use the getter that matches its type.", 16],
            ["Config key 'auth.defaults.guard' is not a float, so float() throws. Correct the config value or use the getter that matches its type.", 17],
            ["Config key 'test.missing' is not a boolean, so boolean() throws. Correct the config value or use the getter that matches its type.", 31],
            ["Config key 'test.foo' is not an integer, so integer() throws. Correct the config value or use the getter that matches its type.", 40],
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
