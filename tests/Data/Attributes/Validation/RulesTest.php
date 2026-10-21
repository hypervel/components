<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes\Validation;

use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\RuleNormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Data\Support\Validation\ValidationRule;
use Hypervel\Data\Support\Validation\ValidationRuleFactory;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Data\Fixtures\Rules\CustomHypervelRule;
use Hypervel\Tests\Data\Fixtures\Rules\CustomHypervelValidationRule;
use Hypervel\Tests\Data\Fixtures\RulesDataset;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Exists as BaseExists;
use Hypervel\Validation\Rules\Unique as BaseUnique;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/Fixtures/RulesDataset.php';

class RulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::create(2020, 05, 16, 0, 0, 0));
    }

    #[DataProvider('attributes')]
    public function testGetsTheCorrectRules(
        ValidationRule $attribute,
        string|object $expected,
        ?ValidationRule $expectedCreatedAttribute,
        ?string $exception = null,
    ): void {
        if ($exception) {
            $this->expectException($exception);
        }

        $resolved = (new RuleDenormalizer)->execute($attribute, ValidationPath::create());

        $this->assertRuleEquals($expected, $resolved[0]);
    }

    #[DataProvider('attributes')]
    public function testCreatesTheCorrectAttributes(
        ValidationRule $attribute,
        string|object $expected,
        ?ValidationRule $expectedCreatedAttribute,
        ?string $exception = null,
    ): void {
        if ($exception) {
            $this->assertTrue(true);

            return;
        }

        $resolved = (new RuleNormalizer(new ValidationRuleFactory))->execute($expected);

        $this->assertRuleEquals($expectedCreatedAttribute, $resolved[0]);
    }

    /**
     * Provide each validation attribute with its compiled rule and the attribute rebuilt from that rule.
     */
    public static function attributes(): iterable
    {
        return RulesDataset\attributes();
    }

    public function testCanUseTheRuleRule(): void
    {
        $rule = new Rule(
            'test',
            ['a', 'b', 'c'],
            'x|y',
            new CustomHypervelRule,
            new Required
        );

        $this->assertEquals(
            ['test', 'a', 'b', 'c', 'x', 'y', new CustomHypervelRule, 'required'],
            (new RuleDenormalizer)->execute($rule, ValidationPath::create()),
        );
    }

    // REMOVED: "can use the Rule rule with invokable rules"; Laravel's deprecated InvokableRule contract is not included.

    public function testCanUseTheRuleRuleWithValidationRuleContract(): void
    {
        $rule = new Rule(
            'test',
            ['a', 'b', 'c'],
            'x|y',
            new CustomHypervelValidationRule,
            new Required
        );

        $this->assertEquals(
            ['test', 'a', 'b', 'c', 'x', 'y', new CustomHypervelValidationRule, 'required'],
            (new RuleDenormalizer)->execute($rule, ValidationPath::create()),
        );
    }

    /**
     * Assert two rules are equal, comparing database query callbacks by identity, since closures cannot be compared.
     */
    protected function assertRuleEquals(mixed $expected, mixed $actual): void
    {
        if ($expected instanceof Exists || $expected instanceof Unique) {
            $this->assertInstanceOf($expected::class, $actual);

            $expected = $expected->getRule(ValidationPath::create());
            $actual = $actual->getRule(ValidationPath::create());
        }

        if ($expected instanceof BaseExists || $expected instanceof BaseUnique) {
            $this->assertInstanceOf($expected::class, $actual);
            $this->assertSame((string) $expected, (string) $actual);
            $this->assertSame($expected->queryCallbacks(), $actual->queryCallbacks());

            return;
        }

        $this->assertEquals($expected, $actual);
    }
}
