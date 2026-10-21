<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Closure;
use Hypervel\Contracts\Validation\Rule as CustomRuleContract;
use Hypervel\Data\Attributes\Validation\AcceptedIf;
use Hypervel\Data\Attributes\Validation\After;
use Hypervel\Data\Attributes\Validation\BeforeOrEqual;
use Hypervel\Data\Attributes\Validation\DateEquals;
use Hypervel\Data\Attributes\Validation\DeclinedIf;
use Hypervel\Data\Attributes\Validation\Dimensions;
use Hypervel\Data\Attributes\Validation\ExcludeIf;
use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\NotRegex;
use Hypervel\Data\Attributes\Validation\Regex;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\RuleNormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\Fluent;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Rules\CustomHypervelRule;
use Hypervel\Tests\Data\Fixtures\Rules\CustomHypervelValidationRule;
use Hypervel\Validation\Rule as ValidationRule;
use Hypervel\Validation\Rules\Exists as BaseExists;
use PHPUnit\Framework\Attributes\DataProvider;

class RuleNormalizerTest extends TestCase
{
    protected RuleNormalizer $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = $this->app->make(RuleNormalizer::class);
    }

    public function testCanMapStringRules(): void
    {
        $this->assertEquals([new Required], $this->mapper->execute(['required']));
    }

    public function testCanMapStringRulesWithArguments(): void
    {
        $this->assertEquals([new Exists(rule: new BaseExists('users'))], $this->mapper->execute(['exists:users']));
    }

    public function testCanMapStringRulesWithKeyValueArguments(): void
    {
        $this->assertEquals(
            [new Dimensions(minWidth: 100, minHeight: 200)],
            $this->mapper->execute(['dimensions:min_width=100,min_height=200']),
        );
    }

    public function testCanMapStringRulesWithRegex(): void
    {
        $this->assertEquals([new Regex('/test|ok/')], $this->mapper->execute(['regex:/test|ok/']));
    }

    public function testSplitsRulesBeforeARegex(): void
    {
        $this->assertEquals([new NotRegex('/test|ok/')], $this->mapper->execute(['not_regex:/test|ok/']));
        $this->assertEquals([new Required, new Regex('/^a/')], $this->mapper->execute(['required|regex:/^a/']));
        $this->assertEquals([new Required, new NotRegex('/^a/')], $this->mapper->execute(['required|not_regex:/^a/']));
    }

    public function testKeepsRegexRuleAliasesWhole(): void
    {
        $this->assertEquals([new Regex('/test|ok/')], $this->mapper->execute(['Regex:/test|ok/']));
        $this->assertEquals([new Rule('notregex:/test|ok/')], $this->mapper->execute(['notregex:/test|ok/']));
    }

    public function testCanMapMultipleRules(): void
    {
        $this->assertEquals([new Required, new Min(0)], $this->mapper->execute(['required', 'min:0']));
    }

    public function testCanMapMultipleConcatenatedRules(): void
    {
        $this->assertEquals([new Required, new Min(0)], $this->mapper->execute(['required|min:0']));
    }

    public function testCanMapFaultyRules(): void
    {
        $this->assertEquals([new Rule('min:')], $this->mapper->execute(['min:']));
    }

    public function testCanMapHypervelRuleObjects(): void
    {
        $this->assertEquals(
            [new Exists(rule: new BaseExists('users'))],
            $this->mapper->execute([new BaseExists('users')]),
        );
    }

    public function testCanMapACustomHypervelRuleObjects(): void
    {
        $rule = new class implements CustomRuleContract {
            /**
             * Determine if the validation rule passes.
             */
            public function passes(string $attribute, mixed $value): bool
            {
                return true;
            }

            /**
             * Get the validation error message.
             */
            public function message(): array|string
            {
                return '';
            }
        };

        $this->assertEquals([new Rule($rule)], $this->mapper->execute([$rule]));
    }

    #[DataProvider('rulesNoAttributeCanHold')]
    public function testKeepsRulesNoAttributeCanHoldAsWritten(string $rule): void
    {
        $this->assertEquals([new Rule($rule)], $this->mapper->execute($rule));
    }

    /**
     * Provide rule strings whose parameters, or keyword, no validation attribute can hold.
     */
    public static function rulesNoAttributeCanHold(): iterable
    {
        yield ['alpha:ascii'];
        yield ['alpha_dash:ascii'];
        yield ['alpha_num:ascii'];
        yield ['boolean:strict'];
        yield ['integer:strict'];
        yield ['numeric:strict'];
        yield ['confirmed:repeat_field'];
        yield ['image:allow_svg'];
        yield ['timezone:per_country,US'];
        yield ['uuid:4'];
        yield ['accepted_if:field,a,b'];
        yield ['exclude_unless:field,a,b'];
        yield ['exists:users,email,status,"active"'];
        yield ['unique:users,email,5,uid,status,"active"'];
        yield ['dimensions:min_ratio=1/2,max_ratio=2/1'];
        yield ['can:update,post'];
        yield ['unknown_rule:a'];
    }

    #[DataProvider('typedRulesWithParameters')]
    public function testRebuildsTypedRulesWithTheirParametersAsWritten(string $rule, string $attributeClass): void
    {
        $normalized = $this->mapper->execute($rule);

        $this->assertCount(1, $normalized);
        $this->assertInstanceOf($attributeClass, $normalized[0]);
        $this->assertSame([$rule], (new RuleDenormalizer)->execute($normalized, ValidationPath::create()));
    }

    /**
     * Provide rule strings whose parameters a typed attribute holds as written.
     */
    public static function typedRulesWithParameters(): iterable
    {
        yield ['accepted_if:status,1', AcceptedIf::class];
        yield ['declined_if:status,0', DeclinedIf::class];
        yield ['exclude_if:status,1', ExcludeIf::class];
        yield ['after:2020-01-01', After::class];
        yield ['before_or_equal:2020-01-01 12:00', BeforeOrEqual::class];
        yield ['date_equals:today', DateEquals::class];
    }

    #[DataProvider('validatorRuleObjects')]
    public function testKeepsValidatorRuleObjectsAsGiven(object $rule): void
    {
        $normalized = $this->mapper->execute([$rule]);

        $this->assertCount(1, $normalized);
        $this->assertInstanceOf(Rule::class, $normalized[0]);
        $this->assertSame([$rule], $normalized[0]->get());
    }

    /**
     * Provide rule objects the validator accepts that have no typed attribute.
     */
    public static function validatorRuleObjects(): iterable
    {
        yield 'closure' => [static function (string $attribute, mixed $value, Closure $fail): void {}];
        yield 'fluent rule' => [ValidationRule::date()];
        yield 'nested rules' => [ValidationRule::forEach(static fn (): array => ['integer'])];
        yield 'conditional rules' => [ValidationRule::when(true, ['min:3'])];
        yield 'custom rule' => [new CustomHypervelRule];
        yield 'validation rule' => [new CustomHypervelValidationRule];
    }

    public function testKeepsANativeDatabaseRuleAndItsQueryCallbacks(): void
    {
        $rule = (new BaseExists('users'))->where(static fn (): null => null);

        $normalized = $this->mapper->execute([$rule]);

        $this->assertCount(1, $normalized);
        $this->assertInstanceOf(Exists::class, $normalized[0]);
        $this->assertSame($rule, $normalized[0]->getRule(ValidationPath::create()));
    }

    public function testNormalizedRulesValidateLikeTheOriginals(): void
    {
        $rules = [
            'name' => [
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === 'bad') {
                        $fail('The name is bad.');
                    }
                },
                ValidationRule::when(static fn (Fluent $data): bool => $data->strict, ['min:5']),
            ],
            'items.*' => [ValidationRule::forEach(static fn (): array => ['integer'])],
            'date' => [ValidationRule::date()],
        ];
        $denormalizer = new RuleDenormalizer;
        $normalized = array_map(
            fn (array $fieldRules): array => $denormalizer->execute($this->mapper->execute($fieldRules), ValidationPath::create()),
            $rules,
        );

        foreach ([
            [['name' => 'bad', 'strict' => false, 'items' => [1], 'date' => '2020-01-01'], ['name']],
            [['name' => 'okay', 'strict' => true, 'items' => [1], 'date' => '2020-01-01'], ['name']],
            [['name' => 'okay', 'strict' => false, 'items' => ['x'], 'date' => '2020-01-01'], ['items.0']],
            [['name' => 'okay', 'strict' => false, 'items' => [1], 'date' => 'never'], ['date']],
            [['name' => 'okay', 'strict' => false, 'items' => [1], 'date' => '2020-01-01'], []],
        ] as [$data, $errorKeys]) {
            $this->assertSame($errorKeys, $this->app->make('validator')->make($data, $rules)->errors()->keys());
            $this->assertSame($errorKeys, $this->app->make('validator')->make($data, $normalized)->errors()->keys());
        }
    }
}
