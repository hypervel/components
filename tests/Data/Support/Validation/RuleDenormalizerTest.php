<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation;

use Carbon\CarbonTimeZone;
use Hypervel\Data\Attributes\Validation\AcceptedIf;
use Hypervel\Data\Attributes\Validation\After;
use Hypervel\Data\Attributes\Validation\EndsWith;
use Hypervel\Data\Attributes\Validation\ExcludeWithout;
use Hypervel\Data\Attributes\Validation\In;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Attributes\Validation\Rule as RuleAttribute;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\References\RouteParameterReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Concerns\BindsRouteParameters;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\Rules\CustomHypervelRule;
use Hypervel\Tests\Data\Fixtures\Rules\CustomHypervelValidationRule;
use Hypervel\Validation\Rule as HypervelRule;
use PHPUnit\Framework\Attributes\DataProvider;

// Upstream comments out this whole file; its cases run here as active tests.
class RuleDenormalizerTest extends TestCase
{
    use BindsRouteParameters;

    #[DataProvider('rules')]
    public function testCanDenormalizeRules(mixed $rule, array $expected, ?ValidationPath $path = null): void
    {
        $denormalizer = new RuleDenormalizer;

        $this->assertEquals($expected, $denormalizer->execute($rule, $path ?? ValidationPath::create(null)));
    }

    /**
     * Provide rules and their denormalized form.
     */
    public static function rules(): iterable
    {
        yield 'string rule' => ['string', ['string']];
        yield 'multi rule string' => ['string|required', ['string', 'required']];
        yield 'array rule' => [['string|min:3', 'required'], ['string', 'min:3', 'required']];
        yield 'regex rule with a pipe' => ['regex:/test|ok/', ['regex:/test|ok/']];
        yield 'not regex rule with a pipe' => ['not_regex:/test|ok/', ['not_regex:/test|ok/']];
        yield 'rules before a regex' => ['required|regex:/^a/', ['required', 'regex:/^a/']];
        yield 'rules before a not regex' => ['required|not_regex:/^a/', ['required', 'not_regex:/^a/']];
        yield 'string validation attribute rule' => [new Required, ['required']];
        yield 'string validation attribute rule with parameters' => [new Min(3), ['min:3']];
        yield 'string validation attribute rule with parameters to normalize' => [new AcceptedIf('field', DummyBackedEnum::BOO), ['accepted_if:field,boo']];
        yield 'object validation attribute rule' => [new In('a', 'b'), [HypervelRule::in('a', 'b')]];
        yield 'rule attribute rule' => [new RuleAttribute('string|required', new Min(3)), ['string', 'required', 'min:3']];
        yield 'hypervel custom rule' => [new CustomHypervelRule, [new CustomHypervelRule]];
        // REMOVED: Laravel's deprecated InvokableRule contract is not included; ValidationRule replaces it.
        yield 'hypervel validation rule' => [new CustomHypervelValidationRule, [new CustomHypervelValidationRule]];

        // Parameters
        yield 'boolean true parameter' => [new AcceptedIf('field', true), ['accepted_if:field,true']];
        yield 'boolean false parameter' => [new AcceptedIf('field', false), ['accepted_if:field,false']];
        yield 'enum parameter' => [new AcceptedIf('field', DummyBackedEnum::BOO), ['accepted_if:field,boo']];
        yield 'empty array parameter' => [new EndsWith, ['ends_with']];
        yield 'array parameter' => [new EndsWith(['test', DummyBackedEnum::BOO]), ['ends_with:test,boo']];
        yield 'date parameter' => [new After(CarbonImmutable::create(2020, 05, 16, 12, timezone: new CarbonTimeZone('Europe/Brussels'))), ['after:2020-05-16T12:00:00+02:00']];
        yield 'field root reference parameter' => [new ExcludeWithout(new FieldReference('field')), ['exclude_without:field']];
        yield 'field nested reference parameter' => [new ExcludeWithout(new FieldReference('field')), ['exclude_without:nested.field'], ValidationPath::create('nested')];
    }

    public function testCanDenormalizeRulesWithRouteParameterReferences(): void
    {
        $this->bindRouteParameters(['parameter' => '69']);

        $denormalizer = new RuleDenormalizer;

        $this->assertSame(
            ['min:69'],
            $denormalizer->execute(new Min(new RouteParameterReference('parameter')), ValidationPath::create(null)),
        );
    }
}
