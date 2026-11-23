<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\RestrictedAttributeRule;
use Hypervel\Tests\PHPStan\Rules\Coroutine\RestrictedAttributes\Registry;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<RestrictedAttributeRule> */
class RestrictedAttributeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(RestrictedAttributeRule::class);
    }

    public function testRule(): void
    {
        $registry = Registry::class;

        $this->analyse([__DIR__ . '/data/restricted-attributes.php'], [
            [
                "#[BootOnly] on {$registry}::both() sets both whenNotDefault and whenProvided, but a marker takes one parameter condition.",
                13,
                'Keep the condition that decides whether a call is restricted.',
            ],
            [
                "#[BootOnly] on {$registry}::missingDefault() names \$missing in whenNotDefault, but the method has no such parameter.",
                18,
                'Name the parameter whose argument makes a call restricted.',
            ],
            [
                "#[TestsOnly] on {$registry}::missingProvided() names \$missing in whenProvided, but the method has no such parameter.",
                23,
                'Name the parameter whose argument makes a call restricted.',
            ],
            [
                "#[BootOnly] on {$registry}::required() names \$map in whenNotDefault, but \$map has no default, so every call is restricted.",
                28,
                'Name an optional parameter, or remove the condition.',
            ],
            [
                "#[BootOrTestsOnly] on {$registry}::staticShared() sets whenShared, but a static method has no instance for the container to share.",
                33,
                'Remove whenShared, so the restriction applies to every call.',
            ],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
