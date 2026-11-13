<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\SocialitePropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<SocialitePropertyRule> */
class SocialitePropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(SocialitePropertyRule::class);
    }

    public function testRule(): void
    {
        $error = static fn (string $property, string $getter, string $setter, int $line): array => [
            "\$this->{$property} holds the provider's default for every request, not the current request's value.",
            $line,
            "Read it with {$getter}() and set it with {$setter}(), which use the provider's coroutine context.",
        ];

        $this->analyse([__DIR__ . '/data/socialite-properties.php'], [
            $error('clientSecret', 'getClientSecret', 'setConfig', 26),
            $error('scopes', 'getScopes', 'setScopes', 38),
            $error('parameters', 'getParameters', 'with', 43),
            $error('popup', 'getPopup', 'asPopup', 61),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
