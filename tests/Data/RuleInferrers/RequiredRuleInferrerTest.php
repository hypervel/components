<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\RuleInferrers\RequiredRuleInferrerTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Validation\BooleanType;
use Hypervel\Data\Attributes\Validation\Enum;
use Hypervel\Data\Attributes\Validation\Nullable;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Optional;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Validation\Rules\Enum as BaseEnum;

class RequiredRuleInferrerTest extends TestCase
{
    // Spatie's RequiredRuleInferrer class is not included; Hypervel infers presence through its fixed rule
    // compilation, before declared attributes and configured inferrers, so each case declares its rules as
    // attributes and reads the compiled rules, which include the inferred type rule.

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    // Upstream's title says "won't add", but the case asserts that the required rule is added.
    public function testWillAddARequiredRuleWhenAPropertyIsNonNullable(): void
    {
        $dataClass = new class extends Data {
            public string $string;
        };

        $this->assertSame(['required', 'string'], $dataClass::getValidationRules([])['string']);
    }

    public function testWontAddARequiredRuleWhenAPropertyIsNullable(): void
    {
        $dataClass = new class extends Data {
            public ?string $string;
        };

        $this->assertSame(['nullable', 'string'], $dataClass::getValidationRules([])['string']);
    }

    public function testWontAddARequiredRuleWhenAPropertyAlreadyContainsARequiredRule(): void
    {
        $dataClass = new class extends Data {
            #[RequiredIf('bla')]
            public string $string;
        };

        $this->assertSame(['string', 'required_if:bla'], $dataClass::getValidationRules([])['string']);
    }

    public function testWontAddARequiredRuleWhenAPropertyAlreadyContainsARequiredObjectRule(): void
    {
        $dataClass = new class extends Data {
            #[Required]
            public string $string;
        };

        $this->assertSame(['string', 'required'], $dataClass::getValidationRules([])['string']);
    }

    public function testWillAddARequiredRuleWhenAPropertyContainsABooleanRule(): void
    {
        $dataClass = new class extends Data {
            #[BooleanType]
            public string $string;
        };

        $this->assertSame(['required', 'string', 'boolean'], $dataClass::getValidationRules([])['string']);
    }

    // Upstream's inferrer skips required when a nullable rule precedes it, which only a custom inferrer
    // ordered before it can add. Declared attributes follow inference here, as in upstream's default
    // inferrer order, so a declared nullable rule keeps the required rule.
    public function testKeepsTheRequiredRuleWhenANullableRuleIsDeclared(): void
    {
        $dataClass = new class extends Data {
            #[Nullable]
            public string $string;
        };

        $this->assertSame(['required', 'string', 'nullable'], $dataClass::getValidationRules([])['string']);
    }

    public function testHasSupportForRulesThatCannotBeConvertedToString(): void
    {
        $dataClass = new class extends Data {
            #[Enum(new BaseEnum(DummyBackedEnum::class))]
            public string $string;
        };

        $this->assertEquals(
            ['required', 'string', new BaseEnum(DummyBackedEnum::class)],
            $dataClass::getValidationRules([])['string'],
        );
    }

    public function testWontAddRequiredToADataCollectionSinceItIsAlreadyPresent(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $collection;
        };

        $this->assertSame(['present', 'array'], $dataClass::getValidationRules([])['collection']);
    }

    // Upstream passes an array where the inferrer expects PropertyRules and only asserts the resulting TypeError.
    public function testWontAddRequiredRulesToUndefinableProperties(): void
    {
        $dataClass = new class extends Data {
            public string|Optional $string;
        };

        $this->assertSame(['sometimes', 'string'], $dataClass::getValidationRules([])['string']);
    }
}
