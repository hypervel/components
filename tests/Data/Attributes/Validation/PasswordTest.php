<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use Hypervel\Data\Attributes\Validation\Password as PasswordAttribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Password as PasswordRule;
use PHPUnit\Framework\Attributes\DataProvider;

class PasswordTest extends TestCase
{
    /**
     * Test an explicitly supplied native rule is preserved.
     */
    public function testReturnsProvidedRule(): void
    {
        $rule = PasswordRule::min(16);

        $this->assertSame(
            $rule,
            (new PasswordAttribute(rule: $rule))->getRule(ValidationPath::create()),
        );
    }

    /**
     * Test direct parameters configure the native rule.
     */
    public function testBuildsConfiguredRule(): void
    {
        $rule = (new PasswordAttribute(
            min: 12,
            letters: true,
            mixedCase: true,
            numbers: true,
            symbols: true,
            uncompromised: true,
            uncompromisedThreshold: 7,
        ))->getRule(ValidationPath::create());

        $this->assertSame([
            'min' => 12,
            'max' => null,
            'mixedCase' => true,
            'letters' => true,
            'numbers' => true,
            'symbols' => true,
            'uncompromised' => true,
            'compromisedThreshold' => 7,
            'customRules' => [],
        ], $rule->appliedRules());
    }

    /**
     * Test externally resolved parameters configure the native rule.
     */
    public function testBuildsConfiguredRuleFromExternalReferences(): void
    {
        $rule = (new PasswordAttribute(
            min: new PasswordExternalReference(10),
            letters: new PasswordExternalReference(true),
            mixedCase: new PasswordExternalReference(true),
            numbers: new PasswordExternalReference(true),
            symbols: new PasswordExternalReference(true),
            uncompromised: new PasswordExternalReference(true),
            uncompromisedThreshold: new PasswordExternalReference(3),
            default: new PasswordExternalReference(false),
        ))->getRule(ValidationPath::create());

        $this->assertSame([
            'min' => 10,
            'max' => null,
            'mixedCase' => true,
            'letters' => true,
            'numbers' => true,
            'symbols' => true,
            'uncompromised' => true,
            'compromisedThreshold' => 3,
            'customRules' => [],
        ], $rule->appliedRules());
    }

    /**
     * Test the attribute uses the framework's default password rule.
     */
    public function testUsesDefaultPasswordRule(): void
    {
        PasswordRule::defaults(fn () => PasswordRule::min(42)->uncompromised(7));

        $rule = (new PasswordAttribute(default: true))->getRule(ValidationPath::create());

        $this->assertSame(42, $rule->appliedRules()['min']);
        $this->assertTrue($rule->appliedRules()['uncompromised']);
        $this->assertSame(7, $rule->appliedRules()['compromisedThreshold']);
    }

    /**
     * Test the attribute's ordinary minimum remains independent of the framework default.
     */
    public function testUsesConfiguredMinimumWhenDefaultIsDisabled(): void
    {
        PasswordRule::defaults(fn () => PasswordRule::min(42));

        $rule = (new PasswordAttribute)->getRule(ValidationPath::create());

        $this->assertSame(12, $rule->appliedRules()['min']);
    }

    /**
     * Test invalid externally resolved parameters fail clearly.
     */
    #[DataProvider('invalidResolvedParameters')]
    public function testRejectsInvalidResolvedParameter(PasswordAttribute $attribute, string $message): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessage($message);

        $attribute->getRule(ValidationPath::create());
    }

    /**
     * Provide invalid externally resolved parameter values.
     */
    public static function invalidResolvedParameters(): iterable
    {
        yield [
            new PasswordAttribute(min: new PasswordExternalReference('12')),
            'Password min must resolve to an integer.',
        ];
        yield [
            new PasswordAttribute(letters: new PasswordExternalReference(1)),
            'Password letters must resolve to a boolean.',
        ];
        yield [
            new PasswordAttribute(mixedCase: new PasswordExternalReference(1)),
            'Password mixedCase must resolve to a boolean.',
        ];
        yield [
            new PasswordAttribute(numbers: new PasswordExternalReference(1)),
            'Password numbers must resolve to a boolean.',
        ];
        yield [
            new PasswordAttribute(symbols: new PasswordExternalReference(1)),
            'Password symbols must resolve to a boolean.',
        ];
        yield [
            new PasswordAttribute(uncompromised: new PasswordExternalReference(1)),
            'Password uncompromised must resolve to a boolean.',
        ];
        yield [
            new PasswordAttribute(uncompromisedThreshold: new PasswordExternalReference('7')),
            'Password uncompromisedThreshold must resolve to an integer.',
        ];
        yield [
            new PasswordAttribute(default: new PasswordExternalReference(1)),
            'Password default must resolve to a boolean.',
        ];
    }

    /**
     * Test password rules cannot be built from string parameters.
     */
    public function testCannotCreateFromStringParameters(): void
    {
        $this->expectException(CannotBuildValidationRule::class);
        $this->expectExceptionMessage('Cannot create a password rule from string parameters.');

        PasswordAttribute::create();
    }
}

class PasswordExternalReference implements ExternalReference
{
    public function __construct(protected mixed $value)
    {
    }

    /**
     * Resolve the referenced value.
     */
    public function getValue(): mixed
    {
        return $this->value;
    }
}
