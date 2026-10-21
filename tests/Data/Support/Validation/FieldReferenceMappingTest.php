<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Validation\FieldReferenceMappingTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\Validation\After;
use Hypervel\Data\Attributes\Validation\Different;
use Hypervel\Data\Attributes\Validation\ExcludeIf;
use Hypervel\Data\Attributes\Validation\GreaterThan;
use Hypervel\Data\Attributes\Validation\InArray;
use Hypervel\Data\Attributes\Validation\MissingIf;
use Hypervel\Data\Attributes\Validation\PresentWith;
use Hypervel\Data\Attributes\Validation\ProhibitedIf;
use Hypervel\Data\Attributes\Validation\Prohibits;
use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Attributes\Validation\RequiredWith;
use Hypervel\Data\Attributes\Validation\RequiredWithAll;
use Hypervel\Data\Attributes\Validation\RequiredWithout;
use Hypervel\Data\Attributes\Validation\RequiredWithoutAll;
use Hypervel\Data\Attributes\Validation\Rule;
use Hypervel\Data\Attributes\Validation\Same;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\RuleInferrers\RuleInferrer;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Validation\PropertyRules;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Data\Support\Validation\ValidationRule;
use Hypervel\Http\Request;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class FieldReferenceMappingTest extends TestCase
{
    /**
     * Register the data provider for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    #[DataProvider('mappedClasses')]
    public function testMappedReferencesEnforceConditionsForRequestsAndExplicitValidation(string $class): void
    {
        foreach ([false, true] as $request) {
            try {
                $request
                    ? $class::from(Request::create('/', 'POST', ['last_name' => 'Taylor']))
                    : $class::validate(['last_name' => 'Taylor']);
                $this->fail('Expected the mapped field to require the title.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('title', $exception->errors());
            }
        }
    }

    /**
     * Provide the supported forms of mapped attribute references.
     */
    public static function mappedClasses(): iterable
    {
        yield 'property mapper' => [PropertyMappedData::class];
        yield 'class mapper' => [ClassMappedData::class];
        yield 'Rule string attribute' => [RuleMappedData::class];
        yield 'attribute returned by rules' => [ClassRulesMappedData::class];
        yield 'preserved input' => [PreservedMappedData::class];
        yield 'explicit input name' => [InputNamedData::class];
    }

    #[WithConfig('data.name_mapping_strategy', ['input' => SnakeCaseMapper::class, 'output' => null])]
    public function testGlobalMappingAndDisabledMappingUseTheEffectiveInputName(): void
    {
        $this->assertContains('required_with:last_name', GlobalMappedData::getValidationRules([])['title']);
        $this->assertContains(
            'required_with:lastName',
            GlobalMappedData::factory()->withoutPropertyNameMapping()->getValidationRules([])['title'],
        );
    }

    public function testDottedMappingAndUndeclaredReferencesRetainTheirInputPaths(): void
    {
        $rules = DottedMappedData::getValidationRules([]);
        $this->assertContains('required_with:profile.name', $rules['title']);
        $this->assertContains('required_with:confirmation', $rules['other']);

        try {
            DottedMappedData::validate(['profile' => ['name' => 'Taylor']]);
            $this->fail('Expected the dotted input to require the title.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }
    }

    public function testClassRuleStringsRemainLiteral(): void
    {
        $rules = LiteralRulesData::getValidationRules([]);
        $this->assertSame(['required_with:lastName'], $rules['title']);
    }

    #[DataProvider('referenceRules')]
    public function testRuleHooksResolveFieldParametersWithoutChangingOtherParameters(ValidationRule $rule, string $expected): void
    {
        foreach (['beforeRules', 'afterRules'] as $stage) {
            $factory = PropertyMappedData::factory();

            if ($stage === 'beforeRules') {
                $factory->beforeRules(static fn (DataProperty $property): ?array => $property->name === 'title' ? [$rule] : null);
            } else {
                $factory->afterRules(static fn (array $rules, DataProperty $property): array => $property->name === 'title' ? [$rule] : $rules);
            }

            $this->assertContains($expected, $factory->getValidationRules([])['title']);
        }
    }

    /**
     * Provide conditional, comparison, exclusion and date field references.
     */
    public static function referenceRules(): iterable
    {
        yield 'required if' => [new RequiredIf('lastName', 'lastName'), 'required_if:last_name,lastName'];
        yield 'required with' => [new RequiredWith('lastName'), 'required_with:last_name'];
        yield 'required with all' => [new RequiredWithAll('lastName', 'confirmation'), 'required_with_all:last_name,confirmation'];
        yield 'required without' => [new RequiredWithout('lastName'), 'required_without:last_name'];
        yield 'required without all' => [new RequiredWithoutAll('lastName'), 'required_without_all:last_name'];
        yield 'same' => [new Same('lastName'), 'same:last_name'];
        yield 'different' => [new Different('lastName'), 'different:last_name'];
        yield 'greater than' => [new GreaterThan('lastName'), 'gt:last_name'];
        yield 'in array' => [new InArray('lastName.*'), 'in_array:last_name.*'];
        yield 'exclude if' => [new ExcludeIf('lastName', 'x'), 'exclude_if:last_name,x'];
        yield 'prohibited if' => [new ProhibitedIf('lastName', 'x'), 'prohibited_if:last_name,x'];
        yield 'prohibits' => [new Prohibits('lastName', 'confirmation'), 'prohibits:last_name,confirmation'];
        yield 'present with' => [new PresentWith('lastName'), 'present_with:last_name'];
        yield 'missing if' => [new MissingIf('lastName', 'x'), 'missing_if:last_name,x'];
        yield 'date reference' => [new After(new FieldReference('lastName')), 'after:last_name'];
    }

    #[DataProvider('nestedPayloads')]
    public function testNestedAndRootReferencesWorkForObjectsAndCollectionItems(array $payload, array $errorKeys): void
    {
        try {
            NestedRootData::validate($payload);
            $this->fail('Expected the mapped conditions to require the nested titles.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing($errorKeys, array_keys($exception->errors()));
        }
    }

    /**
     * Provide nested objects, uniform items and items with different input shapes.
     */
    public static function nestedPayloads(): iterable
    {
        yield 'relative object' => [
            ['child' => ['last_name' => 'Taylor']],
            ['child.title'],
        ];
        yield 'root and nested root paths' => [
            ['require_title' => true, 'meta' => ['require_title' => true], 'child' => []],
            ['child.rootTitle', 'child.headerTitle'],
        ];
        yield 'uniform collection' => [
            ['items' => [['last_name' => 'Taylor'], ['last_name' => 'River']]],
            ['items.0.title', 'items.1.title'],
        ];
        yield 'different item shapes' => [
            ['items' => [['last_name' => 'Taylor'], []]],
            ['items.0.title'],
        ];
    }

    public function testReferenceTraversesMappedCollectionPropertiesAndItemFields(): void
    {
        $rules = NestedRootData::factory()
            ->beforeRules(static fn (DataProperty $property): ?array => $property->name === 'requireTitle'
                ? [new RequiredWith('entries.*.lastName')]
                : null)
            ->getValidationRules([]);

        $this->assertContains('required_with:items.*.last_name', $rules['require_title']);
    }

    public function testRootCollectionReferencesRemainLiteral(): void
    {
        try {
            RootCollectionItemData::factory()->alwaysValidate()->collect([
                ['last_name' => 'Taylor'],
                ['last_name' => 'River'],
            ]);
            $this->fail('Expected the literal root path to require both titles.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['0.title', '1.title'], array_keys($exception->errors()));
        }
    }

    public function testRootReferencesUseTheSelectedConcreteClass(): void
    {
        try {
            MorphRootData::validate(['type' => 'selected', 'require_title' => true, 'child' => ['last_name' => null]]);
            $this->fail('Expected the selected root class mapping to apply.');
        } catch (ValidationException $exception) {
            $this->assertSame(['child.rootTitle'], array_keys($exception->errors()));
        }
    }

    #[WithConfig('data.rule_inferrers', [MappedReferenceInferrer::class])]
    public function testCustomInferrersUseMappedFieldReferences(): void
    {
        try {
            InferrerData::validate(['last_name' => 'Taylor']);
            $this->fail('Expected the inferrer condition to use the mapped name.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }
    }

    public function testFinishedValuesStillApplyMappedExclusionConditions(): void
    {
        $data = FinishedValueData::validateAndCreate([
            'last_name' => 'hidden',
            'child' => HeaderData::from(['require_title' => false]),
        ]);

        $this->assertNull($data->child);
    }
}

class PropertyMappedData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    #[RequiredWith('lastName')]
    public ?string $title;
}

#[MapInputName(SnakeCaseMapper::class)]
class ClassMappedData extends Data
{
    public ?string $lastName;

    #[RequiredWith('lastName')]
    public ?string $title;
}

class GlobalMappedData extends Data
{
    public ?string $lastName;

    #[RequiredWith('lastName')]
    public ?string $title;
}

class RuleMappedData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    #[Rule('required_with:lastName')]
    public ?string $title;
}

class ClassRulesMappedData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    public ?string $title;

    /**
     * Require a title when the mapped property is supplied.
     */
    public static function rules(): array
    {
        return ['title' => [new RequiredWith('lastName')]];
    }
}

class PreservedMappedData extends Data
{
    #[WithoutValidation, MapInputName('last_name')]
    public ?string $lastName;

    #[RequiredWith('lastName')]
    public ?string $title;
}

class InputNamedData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    #[RequiredWith('last_name')]
    public ?string $title;
}

class DottedMappedData extends Data
{
    #[MapInputName('profile.name')]
    public ?string $lastName;

    #[RequiredWith('lastName')]
    public ?string $title;

    #[RequiredWith('confirmation')]
    public ?string $other;
}

class LiteralRulesData extends ClassRulesMappedData
{
    /**
     * Leave ordinary validator rule strings in input-name space.
     */
    public static function rules(): array
    {
        return ['title' => ['required_with:lastName']];
    }
}

class NestedRootData extends Data
{
    #[MapInputName('require_title')]
    public ?bool $requireTitle;

    #[MapInputName('meta')]
    public ?HeaderData $header;

    public ?NestedItemData $child;

    /** @var null|array<int, NestedItemData> */
    #[MapInputName('items')]
    public ?array $entries;
}

class HeaderData extends Data
{
    #[MapInputName('require_title')]
    public bool $requireTitle;
}

class NestedItemData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    #[RequiredWith('lastName')]
    public ?string $title;

    #[RequiredIf(new FieldReference('requireTitle', fromRoot: true), true)]
    public ?string $rootTitle;

    #[RequiredIf(new FieldReference('header.requireTitle', fromRoot: true), true)]
    public ?string $headerTitle;
}

class RootCollectionItemData extends Data
{
    #[MapInputName('last_name')]
    public string $lastName;

    #[RequiredWith(new FieldReference('0.last_name', fromRoot: true))]
    public ?string $title;
}

abstract class MorphRootData extends Data implements PropertyMorphableData
{
    #[PropertyForMorph]
    public string $type;

    /**
     * Select the root class from the discriminator.
     */
    public static function morph(array $properties): ?string
    {
        return $properties['type'] === 'selected' ? SelectedRootData::class : null;
    }
}

class SelectedRootData extends MorphRootData
{
    #[MapInputName('require_title')]
    public bool $requireTitle;

    public NestedItemData $child;
}

class InferrerData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    public ?string $title;
}

class MappedReferenceInferrer implements RuleInferrer
{
    /**
     * Add the mapped condition to the title's rules.
     */
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($property->name === 'title') {
            $rules->add(new RequiredWith('lastName'));
        }

        return $rules;
    }
}

class FinishedValueData extends Data
{
    #[MapInputName('last_name')]
    public ?string $lastName;

    #[ExcludeIf('lastName', 'hidden')]
    public ?HeaderData $child;
}
