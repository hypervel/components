<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Http\UploadedFile;
use Hypervel\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\JsonSchema\Rules\DistinctArrayItems;
use Hypervel\JsonSchema\Rules\MatchesAnyOf;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\ValidationRules;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Validator;
use Hypervel\Tests\JsonSchema\Fixtures\Enums\StringBackedEnum;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

class ValidationRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));

        Facade::setFacadeApplication($container);
    }

    #[DataProvider('schemaCases')]
    public function testAValuePassesOrFailsTheSchemaItWasDeclaredWith(Closure $properties, array $data, bool $passes): void
    {
        $this->assertSame($passes, $this->passes($this->strict($properties), $data));
    }

    public static function schemaCases(): array
    {
        return [
            'required present' => [fn ($s) => ['name' => $s->string()->required()], ['name' => 'Taylor'], true],
            'required missing' => [fn ($s) => ['name' => $s->string()->required()], [], false],
            'required null' => [fn ($s) => ['name' => $s->string()->required()], ['name' => null], false],
            'optional missing' => [fn ($s) => ['name' => $s->string()], [], true],
            'nullable null' => [fn ($s) => ['name' => $s->string()->nullable()->required()], ['name' => null], true],
            'string given integer' => [fn ($s) => ['name' => $s->string()->required()], ['name' => 5], false],
            'string too long' => [fn ($s) => ['name' => $s->string()->max(3)->required()], ['name' => 'Taylor'], false],
            'string off pattern' => [fn ($s) => ['code' => $s->string()->pattern('^[A-Z]{3}$')->required()], ['code' => 'ab/'], false],
            'string on pattern' => [fn ($s) => ['code' => $s->string()->pattern('^[A-Z]{3}$')->required()], ['code' => 'LIS'], true],
            'string on an escaped slash pattern' => [fn ($s) => ['path' => $s->string()->pattern('^a\/b$')->required()], ['path' => 'a/b'], true],
            'date-time in RFC 3339' => [fn ($s) => ['at' => $s->string()->format('date-time')->required()], ['at' => '2026-11-06T10:30:00.250+05:30'], true],
            'date-time in UTC' => [fn ($s) => ['at' => $s->string()->format('date-time')->required()], ['at' => '2026-11-06T10:30:00Z'], true],
            'date-time as a relative phrase' => [fn ($s) => ['at' => $s->string()->format('date-time')->required()], ['at' => 'tomorrow'], false],
            'date-time on an impossible day' => [fn ($s) => ['at' => $s->string()->format('date-time')->required()], ['at' => '2026-02-30T10:30:00Z'], false],
            'email format' => [fn ($s) => ['email' => $s->string()->format('email')->required()], ['email' => 'not-an-email'], false],
            'enum outside' => [fn ($s) => ['plan' => $s->string()->enum(['Basic', 'Pro'])->required()], ['plan' => 'Gold'], false],
            'enum inside' => [fn ($s) => ['plan' => $s->string()->enum(['Basic', 'Pro'])->required()], ['plan' => 'Pro'], true],
            'integer below minimum' => [fn ($s) => ['seats' => $s->integer()->min(1)->required()], ['seats' => 0], false],
            'integer given float' => [fn ($s) => ['seats' => $s->integer()->required()], ['seats' => 1.5], false],
            'integer given numeric string' => [fn ($s) => ['seats' => $s->integer()->required()], ['seats' => '5'], true],
            'number given numeric string' => [fn ($s) => ['ratio' => $s->number()->required()], ['ratio' => '0.5'], true],
            'boolean given string' => [fn ($s) => ['agree' => $s->boolean()->required()], ['agree' => '1'], true],
            'number above maximum' => [fn ($s) => ['ratio' => $s->number()->max(1)->required()], ['ratio' => 1.5], false],
            'boolean false' => [fn ($s) => ['agree' => $s->boolean()->required()], ['agree' => false], true],
            'array too many items' => [fn ($s) => ['tags' => $s->array()->items($s->string())->max(1)->required()], ['tags' => ['a', 'b']], false],
            'array with a bad item' => [fn ($s) => ['tags' => $s->array()->items($s->string()->enum(['a', 'b']))->required()], ['tags' => ['a', 'c']], false],
            'array given an object' => [fn ($s) => ['tags' => $s->array()->items($s->string())->required()], ['tags' => ['x' => 'a']], false],
            'array with duplicates' => [fn ($s) => ['tags' => $s->array()->items($s->string())->unique()->required()], ['tags' => ['a', 'a']], false],
            'object missing a nested key' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => []], false],
            'object with an unknown key' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => ['city' => 'Lisbon', 'admin' => true]], false],
            'optional empty string' => [fn ($s) => ['note' => $s->string()->enum(['a', 'b'])], ['note' => ''], false],
            'empty string item' => [fn ($s) => ['tags' => $s->array()->items($s->string())->required()], ['tags' => ['']], false],
            'optional nullable null' => [fn ($s) => ['note' => $s->string()->nullable()], ['note' => null], true],
            'optional empty array' => [fn ($s) => ['tags' => $s->array()->items($s->string())], ['tags' => []], true],
            'object valid' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => ['city' => 'Lisbon']], true],
            'required empty array' => [fn ($s) => ['tags' => $s->array()->items($s->string())->required()], ['tags' => []], false],
            'required empty object' => [fn ($s) => ['meta' => $s->object()->required()], ['meta' => []], false],
            'empty object given a key' => [fn ($s) => ['meta' => $s->object()->required()], ['meta' => ['admin' => true]], false],
            'optional object missing with a required key' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])], [], true],
            'optional object given with a missing key' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])], ['address' => []], false],
            'nullable object null with a required key' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->nullable()->required()], ['address' => null], true],
            'required nested key given null' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => ['city' => null]], false],
            'required nested key given an empty string' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->required()], ['address' => ['city' => '']], false],
            'required nested key given an empty array' => [fn ($s) => ['order' => $s->object(['tags' => $s->array()->items($s->string())->required()])->required()], ['order' => ['tags' => []]], false],
            'nullable nested key given null' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->nullable()->required()])->required()], ['address' => ['city' => null]], true],
            'nullable nested key missing' => [fn ($s) => ['address' => $s->object(['city' => $s->string()->nullable()->required()])->required()], ['address' => ['zip' => '1000']], false],
            'optional array of objects missing a required key' => [fn ($s) => ['people' => $s->array()->items($s->object(['age' => $s->integer()->required()]))], ['people' => [['age' => 1], []]], false],
        ];
    }

    public function testAnAnyOfValuePassesWhenAnyBranchMatches(): void
    {
        $schema = $this->schema(fn ($s) => [
            'plan' => $s->anyOf([$s->string()->enum(['Basic', 'Pro']), $s->integer()])->required(),
        ]);

        $this->assertTrue($this->passes($schema, ['plan' => 'Pro']));
        $this->assertTrue($this->passes($schema, ['plan' => 3]));
        $this->assertFalse($this->passes($schema, ['plan' => 'Gold']));
        $this->assertFalse($this->passes($schema, ['plan' => null]));
    }

    public function testAMultiTypeUnionPassesAnyOfItsTypes(): void
    {
        $schema = $this->schema(fn ($s) => ['id' => $s->union(['string', 'integer'])->required()]);

        $this->assertTrue($this->passes($schema, ['id' => 'abc']));
        $this->assertTrue($this->passes($schema, ['id' => 7]));
        $this->assertFalse($this->passes($schema, ['id' => ['a']]));
    }

    public function testPropertyNamesWithADotAreNotTreatedAsNestedPaths(): void
    {
        $schema = $this->schema(fn ($s) => ['v1.0' => $s->integer()->required()]);

        $this->assertTrue($this->passes($schema, ['v1.0' => 1]));
        $this->assertFalse($this->passes($schema, ['v1.0' => 'x']));
    }

    public function testUniqueItemsRefusesEqualObjectItems(): void
    {
        $schema = $this->schema(fn ($s) => ['rows' => $s->array()->items($s->object(['a' => $s->integer()]))->unique()->required()]);

        $this->assertFalse($this->passes($schema, ['rows' => [['a' => 1], ['a' => 1]]]));
        $this->assertTrue($this->passes($schema, ['rows' => [['a' => 1], ['a' => 2]]]));

        $schema = ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'uniqueItems' => true]]];

        $this->assertFalse($this->passes($schema, ['rows' => [(object) ['a' => 1], (object) ['a' => 1]]]));
        $this->assertTrue($this->passes($schema, ['rows' => [(object) ['a' => 1], (object) ['a' => 2]]]));
    }

    public function testAnEnumOfObjectsComparesValuesStructurally(): void
    {
        $schema = $this->schema(fn ($s) => ['point' => $s->object()->enum([['x' => 1, 'y' => 2]])->required()]);

        $this->assertTrue($this->passes($schema, ['point' => ['y' => 2, 'x' => 1]]));
        $this->assertFalse($this->passes($schema, ['point' => ['x' => 1, 'y' => 3]]));
    }

    public function testAnAnyOfValueMustAlsoPassASiblingEnum(): void
    {
        $schema = $this->schema(fn ($s) => [
            'v' => $s->anyOf([$s->string(), $s->integer()])->enum(['allowed', 7])->required(),
        ]);

        $this->assertTrue($this->passes($schema, ['v' => 'allowed']));
        $this->assertTrue($this->passes($schema, ['v' => 7]));
        $this->assertFalse($this->passes($schema, ['v' => 'other']));
    }

    public function testAnAnyOfFailureUsesTheAnyOfTranslation(): void
    {
        $schema = $this->schema(fn ($s) => ['v' => $s->anyOf([$s->string(), $s->integer()])->required()]);

        $validator = Validator::make(['v' => ['a']], JsonSchema::rules($schema));

        $this->assertSame(['validation.any_of'], $validator->errors()->get('v'));
    }

    public function testNumericPropertyNamesKeepTheirOwnRules(): void
    {
        $schema = $this->schema(fn ($s) => ['1' => $s->string(), '2' => $s->integer()->required()]);

        $this->assertFalse($this->passes($schema, [2 => 'x']));
        $this->assertTrue($this->passes($schema, [2 => 7]));
    }

    public function testAPatternWithAnEscapedSlashMatchesALiteralSlash(): void
    {
        $schema = $this->schema(fn ($s) => ['path' => $s->string()->pattern('^a\/b$')->required()]);

        $this->assertTrue($this->passes($schema, ['path' => 'a/b']));
        $this->assertFalse($this->passes($schema, ['path' => 'a\/b']));
    }

    public function testAPatternWithAnEscapedBackslashBeforeASlashMatchesABackslashAndASlash(): void
    {
        $schema = $this->schema(fn ($s) => ['path' => $s->string()->pattern('^a\\\/b$')->required()]);

        $this->assertTrue($this->passes($schema, ['path' => 'a\/b']));
        $this->assertFalse($this->passes($schema, ['path' => 'a/b']));
    }

    public function testAPatternWithAnUnescapedSlashMatchesALiteralSlash(): void
    {
        $schema = $this->schema(fn ($s) => ['path' => $s->string()->pattern('^a/b/c$')->required()]);

        $this->assertTrue($this->passes($schema, ['path' => 'a/b/c']));
        $this->assertFalse($this->passes($schema, ['path' => 'a-b-c']));
    }

    public function testItAcceptsAType(): void
    {
        $schema = JsonSchema::object(fn ($s) => ['name' => $s->string()->required()]);

        $this->assertTrue(Validator::make(['name' => 'Taylor'], JsonSchema::rules($schema))->passes());
        $this->assertFalse(Validator::make([], JsonSchema::rules($schema))->passes());
    }

    public function testItKeysNestedRulesInDotNotation(): void
    {
        $rules = JsonSchema::rules(JsonSchema::object(fn ($s) => [
            'address' => $s->object(['city' => $s->string()]),
            'tags' => $s->array()->items($s->string()),
        ]));

        $this->assertSame(['address', 'address.city', 'tags', 'tags.*'], array_keys($rules));
    }

    public function testMinLength(): void
    {
        $schema = $this->schema(fn ($s) => ['name' => $s->string()->min(3)->required()]);

        $this->assertTrue($this->passes($schema, ['name' => 'abc']));
        $this->assertFalse($this->passes($schema, ['name' => 'ab']));
    }

    public function testNumberMinimum(): void
    {
        $schema = $this->schema(fn ($s) => ['ratio' => $s->number()->min(0.5)->required()]);

        $this->assertTrue($this->passes($schema, ['ratio' => 0.5]));
        $this->assertFalse($this->passes($schema, ['ratio' => 0.4]));
    }

    public function testIntegerMaximum(): void
    {
        $schema = $this->schema(fn ($s) => ['seats' => $s->integer()->max(10)->required()]);

        $this->assertTrue($this->passes($schema, ['seats' => 10]));
        $this->assertFalse($this->passes($schema, ['seats' => 11]));
    }

    public function testIntegerMultipleOf(): void
    {
        $schema = $this->schema(fn ($s) => ['seats' => $s->integer()->multipleOf(5)->required()]);

        $this->assertTrue($this->passes($schema, ['seats' => 15]));
        $this->assertFalse($this->passes($schema, ['seats' => 12]));
    }

    public function testNumberMultipleOf(): void
    {
        $schema = $this->schema(fn ($s) => ['price' => $s->number()->multipleOf(0.25)->required()]);

        $this->assertTrue($this->passes($schema, ['price' => 1.75]));
        $this->assertFalse($this->passes($schema, ['price' => 1.8]));
    }

    public function testMinItems(): void
    {
        $schema = $this->schema(fn ($s) => ['tags' => $s->array()->items($s->string())->min(2)->required()]);

        $this->assertTrue($this->passes($schema, ['tags' => ['a', 'b']]));
        $this->assertFalse($this->passes($schema, ['tags' => ['a']]));
    }

    public function testUniqueItemsWithoutDuplicates(): void
    {
        $schema = $this->schema(fn ($s) => ['tags' => $s->array()->items($s->string())->unique()->required()]);

        $this->assertTrue($this->passes($schema, ['tags' => ['a', 'b']]));
    }

    public function testUniqueItemsComparesScalarItemsStrictly(): void
    {
        $schema = $this->schema(fn ($s) => ['codes' => $s->array()->items($s->string())->unique()->required()]);

        $this->assertTrue($this->passes($schema, ['codes' => ['1', '01']]));
        $this->assertTrue($this->passes($schema, ['codes' => ['1e3', '1000']]));
        $this->assertFalse($this->passes($schema, ['codes' => ['01', '01']]));
    }

    public function testUniqueItemsAreComparedWithinEachArray(): void
    {
        $schema = $this->schema(fn ($s) => ['groups' => $s->array()->items($s->array()->items($s->string())->unique())->required()]);

        $this->assertTrue($this->passes($schema, ['groups' => [['blue', 'red'], ['blue', 'green']]]));

        $validator = Validator::make(
            ['groups' => [['blue', 'red'], ['green', 'green']]],
            JsonSchema::rules($schema),
            ['groups.*.*.distinct' => 'Duplicate color'],
        );

        $this->assertSame(['groups.1.0' => ['Duplicate color'], 'groups.1.1' => ['Duplicate color']], $validator->errors()->messages());

        $schema = $this->schema(fn ($s) => ['tags' => $s->array()->items($s->string())->unique()->required()]);
        $validator = Validator::make(['tags' => ['blue', 'blue']], JsonSchema::rules($schema), ['tags.*.distinct' => 'Duplicate tag']);

        $this->assertSame(['tags.0' => ['Duplicate tag'], 'tags.1' => ['Duplicate tag']], $validator->errors()->messages());
    }

    public function testUniqueItemsHandleSignedZerosAndFiles(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'items' => ['type' => 'array'], 'uniqueItems' => true],
        ], 'required' => ['rows']];
        $file = UploadedFile::fake()->create('photo.jpg');

        $this->assertFalse($this->passes($schema, ['rows' => [[-0.0], [0.0]]]));
        $this->assertTrue($this->passes($schema, ['rows' => [[$file], [UploadedFile::fake()->create('photo.jpg')]]]));
        $this->assertFalse($this->passes($schema, ['rows' => [[$file], [$file]]]));

        $schema = $this->schema(fn ($s) => ['tags' => $s->array()->items($s->string())->unique()->required()]);
        $validator = Validator::make(['tags' => [$file, 'blue']], JsonSchema::rules($schema));

        $this->assertSame(['tags.0'], $validator->errors()->keys());
    }

    public function testUniqueItemsComparesArrayItemsStrictly(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array', 'items' => ['type' => 'object'], 'uniqueItems' => true],
        ], 'required' => ['rows']];

        $this->assertTrue($this->passes($schema, ['rows' => [['code' => '1'], ['code' => '01']]]));
        $this->assertTrue($this->passes($schema, ['rows' => [['code' => '7'], ['code' => 7]]]));
        $this->assertFalse($this->passes($schema, ['rows' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]]]));
    }

    public function testIntegerKeysMatchInAnyOrder(): void
    {
        $ascending = range(0, 10);
        $descending = array_reverse($ascending, true);

        $this->assertSame($ascending, DistinctArrayItems::normalize($ascending));

        $schema = ['type' => 'object', 'properties' => ['rows' => ['type' => 'array', 'uniqueItems' => true]]];

        $this->assertFalse($this->passes($schema, ['rows' => [$ascending, $descending]]));

        $schema = ['type' => 'object', 'properties' => ['value' => ['enum' => [(object) $ascending, 'none']]]];

        $this->assertTrue($this->passes($schema, ['value' => $descending]));
    }

    public function testAnEnumOfArraysComparesValuesStrictly(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'size' => ['enum' => [[10, 20], ['w' => '1', 'h' => '2']]],
        ], 'required' => ['size']];

        $this->assertTrue($this->passes($schema, ['size' => [10, 20]]));
        $this->assertTrue($this->passes($schema, ['size' => ['h' => '2', 'w' => '1']]));
        $this->assertFalse($this->passes($schema, ['size' => true]));
        $this->assertFalse($this->passes($schema, ['size' => ['w' => '01', 'h' => '2.0']]));
    }

    public function testExclusiveNumericBounds(): void
    {
        $schema = $this->schema(fn ($s) => ['price' => $s->number()->required()]);
        $schema['properties']['price']['exclusiveMinimum'] = 0;
        $schema['properties']['price']['exclusiveMaximum'] = 100;

        $this->assertTrue($this->passes($schema, ['price' => 0.01]));
        $this->assertTrue($this->passes($schema, ['price' => 99]));
        $this->assertFalse($this->passes($schema, ['price' => 0]));
        $this->assertFalse($this->passes($schema, ['price' => 100]));
        $this->assertFalse($this->passes($schema, ['price' => -5]));
    }

    public function testExclusiveNumericBoundsAreNotFieldReferences(): void
    {
        $schema = $this->schema(fn ($s) => ['0' => $s->string()->required(), '10' => $s->string()->required(), 'count' => $s->integer()->required()]);
        $schema['properties']['count']['exclusiveMinimum'] = 0;
        $schema['properties']['count']['exclusiveMaximum'] = 10;

        $this->assertTrue($this->passes($schema, ['0' => 'label', '10' => 'label', 'count' => 5]));
        $this->assertFalse($this->passes($schema, ['0' => 'label', '10' => 'label', 'count' => 10]));

        Validator::getTranslator()->addLines(['validation.gt.numeric' => 'The :attribute field must be greater than :value.'], 'en');
        $validator = Validator::make(['0' => 'label', '10' => 'label', 'count' => 0], JsonSchema::rules($schema));

        $this->assertSame(['count' => ['The count field must be greater than 0.']], $validator->errors()->messages());

        $validator = Validator::make(['0' => 'label', '10' => 'label', 'count' => 0], JsonSchema::rules($schema), ['count.gt' => 'Count is too small']);

        $this->assertSame(['count' => ['Count is too small']], $validator->errors()->messages());

        $schema = $this->schema(fn ($s) => ['price.net' => $s->number()->required()]);
        $schema['properties']['price.net']['exclusiveMinimum'] = '5';

        $this->assertTrue($this->passes($schema, ['price.net' => 8]));
        $this->assertFalse($this->passes($schema, ['price.net' => '5.0']));
        $this->assertTrue($this->passes($schema, ['price.net' => '1e2']));

        $validator = Validator::make(['price.net' => '1e2'], JsonSchema::rules($schema))
            ->ensureExponentWithinAllowedRangeUsing(fn () => false);

        $this->assertFalse($validator->passes());
        $this->assertSame(['price.net'], $validator->errors()->keys());

        $schema = $this->schema(fn ($s) => ['ratio' => $s->number()->max(1.000000000000001)->required()]);
        $schema['properties']['ratio']['exclusiveMaximum'] = 1.000000000000001;

        $this->assertTrue($this->passes($schema, ['ratio' => 1]));
        $this->assertTrue($this->passes($schema, ['ratio' => 1.0000000000000004]));

        $schema = $this->schema(fn ($s) => ['step' => $s->number()->required()]);
        $schema['properties']['step']['multipleOf'] = 0.1000000000000001;

        $this->assertTrue($this->passes($schema, ['step' => 0.2000000000000002]));
    }

    public function testIntegerEnum(): void
    {
        $schema = $this->schema(fn ($s) => ['size' => $s->integer()->enum([1, 2, 3])->required()]);

        $this->assertTrue($this->passes($schema, ['size' => 2]));
        $this->assertFalse($this->passes($schema, ['size' => 4]));
    }

    public function testUuidFormat(): void
    {
        $schema = $this->schema(fn ($s) => ['id' => $s->string()->format('uuid')->required()]);

        $this->assertTrue($this->passes($schema, ['id' => '3c8ff5cb-4bc1-457b-a477-1833c477b254']));
        $this->assertFalse($this->passes($schema, ['id' => 'not-a-uuid']));
    }

    public function testUriAndUrlFormats(): void
    {
        foreach (['uri', 'url'] as $format) {
            $schema = $this->schema(fn ($s) => ['link' => $s->string()->format($format)->required()]);

            $this->assertTrue($this->passes($schema, ['link' => 'https://hypervel.org']), $format);
            $this->assertFalse($this->passes($schema, ['link' => 'hypervel']), $format);
        }
    }

    public function testUriFormatFollowsRfc3986(): void
    {
        $schema = $this->schema(fn ($s) => ['link' => $s->string()->format('uri')->required()]);

        foreach ([
            'URN' => 'urn:uuid:f81d4fae-7dec-11d0-a765-00a0c91e6bf6',
            'mailto' => 'mailto:taylor@hypervel.org',
            'file' => 'file:///etc/hosts',
            'IPv6 host' => 'https://user@[2001:db8::1]:8080/a%20b?q=1#top',
            'IPvFuture host' => 'https://[V1F.fe]/',
            'long escaped data URI' => 'data:text/plain,' . str_repeat('%41', 100000),
        ] as $label => $uri) {
            $this->assertTrue($this->passes($schema, ['link' => $uri]), $label);
        }

        foreach (['https://hypervel.org/ü', 'https://hypervel.org/%zz', 'https://hypervel.org/a b', 'https://[zz]/', '/relative', 'x://a@b@c'] as $value) {
            $this->assertFalse($this->passes($schema, ['link' => $value]), $value);
        }

        $validator = Validator::make(['link' => 'not a uri'], JsonSchema::rules($schema), ['link.uri' => 'The link is not a URI.']);

        $this->assertSame(['link' => ['The link is not a URI.']], $validator->errors()->toArray());
    }

    public function testEmailFormatWithAValidAddress(): void
    {
        $schema = $this->schema(fn ($s) => ['email' => $s->string()->format('email')->required()]);

        $this->assertTrue($this->passes($schema, ['email' => 'taylor@hypervel.org']));
    }

    public function testDateFormat(): void
    {
        $schema = $this->schema(fn ($s) => ['on' => $s->string()->format('date')->required()]);

        $this->assertTrue($this->passes($schema, ['on' => '2026-11-06']));
        $this->assertFalse($this->passes($schema, ['on' => '2026-02-30']));
        $this->assertFalse($this->passes($schema, ['on' => '06/11/2026']));
        $this->assertFalse($this->passes($schema, ['on' => '2026-11-06T10:30:00Z']));
    }

    public function testUnknownFormatAddsNoRule(): void
    {
        $schema = $this->schema(fn ($s) => ['host' => $s->string()->format('hostname')->required()]);

        $this->assertTrue($this->passes($schema, ['host' => 'not a hostname']));
    }

    public function testAnnotationsAddNoRules(): void
    {
        $schema = $this->schema(fn ($s) => [
            'name' => $s->string()->title('Name')->description('The name.')->default('Taylor'),
        ]);
        $schema['properties']['name']['examples'] = ['Taylor'];

        $this->assertTrue($this->passes($schema, []));
        $this->assertTrue($this->passes($schema, ['name' => 'Abigail']));
        $this->assertFalse($this->passes($schema, ['name' => 5]));
    }

    public function testRequiredNullableRejectsMissingAndEmptyString(): void
    {
        $schema = $this->schema(fn ($s) => ['note' => $s->string()->nullable()->required()]);

        $this->assertFalse($this->passes($schema, []));
        $this->assertFalse($this->passes($schema, ['note' => '']));
        $this->assertTrue($this->passes($schema, ['note' => 'text']));
    }

    public function testOptionalNullableRejectsEmptyString(): void
    {
        $schema = $this->schema(fn ($s) => ['note' => $s->string()->nullable()]);

        $this->assertFalse($this->passes($schema, ['note' => '']));
        $this->assertTrue($this->passes($schema, []));
    }

    public function testOptionalValueRejectsNull(): void
    {
        $schema = $this->schema(fn ($s) => ['note' => $s->string()]);

        $this->assertFalse($this->passes($schema, ['note' => null]));
    }

    public function testNullableAnyOfAcceptsNull(): void
    {
        $schema = $this->schema(fn ($s) => [
            'plan' => $s->anyOf([$s->string(), $s->integer()])->nullable()->required(),
        ]);

        $this->assertTrue($this->passes($schema, ['plan' => null]));
        $this->assertTrue($this->passes($schema, ['plan' => 'Pro']));
        $this->assertFalse($this->passes($schema, ['plan' => ['a']]));
        $this->assertFalse($this->passes($schema, []));
    }

    public function testAnyOfAcceptsNullWhenABranchAllowsIt(): void
    {
        $direct = $this->schema(fn ($s) => ['plan' => $s->anyOf([$s->string()->nullable(), $s->integer()])->required()]);
        $nested = $this->schema(fn ($s) => ['plan' => $s->anyOf([$s->anyOf([$s->string()])->nullable(), $s->integer()])->required()]);

        foreach ([$direct, $nested] as $schema) {
            $this->assertTrue($this->passes($schema, ['plan' => null]));
            $this->assertTrue($this->passes($schema, ['plan' => 7]));
            $this->assertFalse($this->passes($schema, []));
        }

        $object = fn (array $plan) => ['type' => 'object', 'properties' => ['plan' => $plan], 'required' => ['plan']];
        $nullBranch = $object(['anyOf' => [['type' => 'null', 'anyOf' => [['type' => ['integer', 'null']]]], ['type' => 'string']]]);
        $emptyBranch = $object(['anyOf' => [[], ['type' => 'string']]]);
        $untyped = $object(['description' => 'Any value.']);

        foreach ([$nullBranch, $emptyBranch, $untyped] as $schema) {
            $this->assertTrue($this->passes($schema, ['plan' => null]));
            $this->assertTrue($this->passes($schema, ['plan' => 'pro']));
            $this->assertFalse($this->passes($schema, []));
        }

        $this->assertFalse($this->passes($nullBranch, ['plan' => 7]));
    }

    public function testNullableValueRespectsAnEnumWithoutNull(): void
    {
        $anyOf = $this->schema(fn ($s) => ['plan' => $s->anyOf([$s->string()->nullable(), $s->integer()])->enum(['pro', 7])->required()]);
        $type = $this->schema(fn ($s) => ['plan' => $s->string()->nullable()->enum(['pro'])->required()]);
        $nullBranch = ['type' => 'object', 'properties' => [
            'plan' => ['anyOf' => [['type' => 'null', 'enum' => ['pro']], ['type' => 'string', 'enum' => ['pro']]]],
        ], 'required' => ['plan']];

        foreach ([$anyOf, $type, $nullBranch] as $schema) {
            $this->assertFalse($this->passes($schema, ['plan' => null]));
            $this->assertTrue($this->passes($schema, ['plan' => 'pro']));
        }
    }

    public function testAnEnumListingNullAllowsNull(): void
    {
        $required = ['type' => 'object', 'properties' => ['flag' => ['enum' => [null]]], 'required' => ['flag']];
        $optional = ['type' => 'object', 'properties' => ['flag' => ['enum' => [null]]]];
        $anyOf = ['type' => 'object', 'properties' => ['flag' => ['anyOf' => [['enum' => [null]], ['type' => 'integer']]]], 'required' => ['flag']];

        foreach ([$required, $optional, $anyOf] as $schema) {
            $this->assertTrue($this->passes($schema, ['flag' => null]));
            $this->assertFalse($this->passes($schema, ['flag' => 'x']));
        }

        $this->assertTrue($this->passes($anyOf, ['flag' => 3]));

        $typed = ['type' => 'object', 'properties' => ['flag' => ['type' => 'string', 'enum' => [null, 'a']]], 'required' => ['flag']];

        $this->assertFalse($this->passes($typed, ['flag' => null]));
    }

    public function testRootRequiredKeysWithoutAPropertySchemaMustBePresent(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b', 'c.d']];

        $this->assertFalse($this->passes($schema, ['a' => 'x']));
        $this->assertFalse($this->passes($schema, ['a' => 'x', 'b' => 1]));
        $this->assertTrue($this->passes($schema, ['b' => null, 'c.d' => []]));
    }

    public function testAnyOfBranchChecksNestedObjects(): void
    {
        $schema = $this->schema(fn ($s) => [
            'target' => $s->anyOf([
                $s->object(['url' => $s->string()->format('url')->required()]),
                $s->object(['email' => $s->string()->format('email')->required()]),
            ])->required(),
        ]);

        $this->assertTrue($this->passes($schema, ['target' => ['email' => 'taylor@hypervel.org']]));
        $this->assertFalse($this->passes($schema, ['target' => ['email' => 'nope']]));
    }

    public function testNullableUnionAcceptsNull(): void
    {
        $schema = $this->schema(fn ($s) => ['id' => $s->union(['string', 'integer', 'null'])->required()]);

        $this->assertTrue($this->passes($schema, ['id' => null]));
        $this->assertTrue($this->passes($schema, ['id' => 7]));
        $this->assertFalse($this->passes($schema, ['id' => ['a']]));
    }

    public function testUnionAppliesTypeKeywordsToEachBranch(): void
    {
        $schema = $this->schema(fn ($s) => ['id' => $s->union(['string', 'integer'])->enum(['a', 1])->required()]);

        $this->assertTrue($this->passes($schema, ['id' => 'a']));
        $this->assertTrue($this->passes($schema, ['id' => 1]));
        $this->assertFalse($this->passes($schema, ['id' => 'b']));
    }

    public function testNestedObjectAllowsUnknownKeysUnlessAdditionalPropertiesIsFalse(): void
    {
        $open = $this->schema(fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->required()]);
        $closed = $this->schema(fn ($s) => ['address' => $s->object(['city' => $s->string()->required()])->withoutAdditionalProperties()->required()]);

        $data = ['address' => ['city' => 'Lisbon', 'admin' => true]];

        $this->assertTrue($this->passes($open, $data));
        $this->assertFalse($this->passes($closed, $data));
    }

    public function testArrayItemsAreCheckedAgainstNestedObjectSchemas(): void
    {
        $schema = $this->schema(fn ($s) => ['people' => $s->array()->items($s->object(['age' => $s->integer()->min(0)->required()]))->required()]);

        $this->assertTrue($this->passes($schema, ['people' => [['age' => 1], ['age' => 2]]]));
        $this->assertFalse($this->passes($schema, ['people' => [['age' => 1], ['age' => -1]]]));
    }

    public function testASchemaWithinTheNodeLimitBuildsRules(): void
    {
        $schema = $this->schema(fn ($s) => array_fill(0, JsonSchema::MAX_NODES, $s->string()));

        $this->assertCount(JsonSchema::MAX_NODES, JsonSchema::rules($schema));
    }

    public function testASchemaBeyondTheNodeLimitIsRefused(): void
    {
        $schema = $this->schema(fn ($s) => array_fill(0, JsonSchema::MAX_NODES + 1, $s->string()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('it expands beyond [' . JsonSchema::MAX_NODES . '] fragments');

        JsonSchema::rules($schema);
    }

    public function testPropertyNamesWithAnAsteriskAreNotWildcards(): void
    {
        $schema = $this->schema(fn ($s) => [
            'api*key' => $s->integer()->required(),
            'tag*s' => $s->array()->items($s->string())->unique(),
        ]);

        $this->assertFalse($this->passes($schema, []));
        $this->assertFalse($this->passes($schema, ['api*key' => 'x']));
        $this->assertTrue($this->passes($schema, ['api*key' => 1]));

        $validator = Validator::make(['api*key' => 1, 'tag*s' => ['a', 'a']], JsonSchema::rules($schema));

        $this->assertSame(['tag*s.0', 'tag*s.1'], $validator->errors()->keys());
    }

    public function testPropertyNamesEndingInABackslashRefuseNestedRules(): void
    {
        $schema = $this->schema(fn ($s) => [
            'count\\' => $s->integer()->required(),
            'tags\\' => $s->array()->min(1),
        ]);

        $this->assertTrue($this->passes($schema, ['count\\' => 1, 'tags\\' => ['x']]));
        $this->assertFalse($this->passes($schema, ['count\\' => 'x', 'tags\\' => ['x']]));
        $this->assertFalse($this->passes($schema, ['count\\' => 1, 'tags\\' => []]));

        $empty = ['type' => 'object', 'properties' => ['none\\' => ['type' => 'array', 'items' => false, 'uniqueItems' => true]]];

        $this->assertTrue($this->passes($empty, ['none\\' => []]));
        $this->assertFalse($this->passes($empty, ['none\\' => ['x']]));

        foreach ([
            'tags\\' => fn ($s) => $s->array()->items($s->integer()),
            'meta\\' => fn ($s) => $s->object(['size' => $s->integer()]),
        ] as $name => $property) {
            try {
                JsonSchema::rules($this->schema(fn ($s) => [$name => $property($s)]));

                $this->fail("Expected nested rules under [{$name}] to be refused.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame("The JSON Schema property [{$name}] ends with a backslash, so validation rules cannot be nested under it.", $exception->getMessage());
            }
        }
    }

    public function testANullOnlyTypeAcceptsOnlyNull(): void
    {
        foreach (['null', ['null']] as $type) {
            $schema = ['type' => 'object', 'properties' => ['nothing' => ['type' => $type]], 'required' => ['nothing']];

            $this->assertTrue($this->passes($schema, ['nothing' => null]));
            $this->assertFalse($this->passes($schema, ['nothing' => 42]));
            $this->assertFalse($this->passes($schema, ['nothing' => 'x']));
            $this->assertFalse($this->passes($schema, ['nothing' => []]));
        }
    }

    public function testAnEnumWithArraysComparesScalarsLikeTheInRule(): void
    {
        $schema = ['type' => 'object', 'properties' => ['value' => ['enum' => [true, '01', ['x']]]], 'required' => ['value']];

        $this->assertTrue($this->passes($schema, ['value' => true]));
        $this->assertTrue($this->passes($schema, ['value' => '01']));
        $this->assertFalse($this->passes($schema, ['value' => 'unlisted']));

        $schema['properties']['value']['enum'] = ['01', ['x']];

        $this->assertFalse($this->passes($schema, ['value' => '1']));

        $schema['properties']['value']['enum'] = [StringBackedEnum::One, ['x']];

        $this->assertTrue($this->passes($schema, ['value' => 'one']));
        $this->assertFalse($this->passes($schema, ['value' => 'two']));
    }

    public function testAnObjectEnumComparesTheWholeValue(): void
    {
        $schema = $this->schema(fn ($s) => ['meta' => $s->object()->enum(['a'])]);

        $this->assertFalse($this->passes($schema, ['meta' => []]));
        $this->assertFalse($this->passes($schema, ['meta' => ['a']]));

        $typed = ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'enum' => [(object) ['a' => (object) []], (object) []]]]];
        $untyped = ['type' => 'object', 'properties' => ['meta' => ['enum' => [(object) ['a' => (object) []], 'none']]]];

        foreach ([$typed, $untyped] as $schema) {
            $this->assertTrue($this->passes($schema, ['meta' => ['a' => []]]));
            $this->assertFalse($this->passes($schema, ['meta' => ['a' => 1]]));
        }

        $this->assertTrue($this->passes($typed, ['meta' => []]));
        $this->assertTrue($this->passes($untyped, ['meta' => 'none']));
        $this->assertTrue($this->passes($untyped, ['meta' => (object) ['a' => (object) []]]));
        $this->assertFalse($this->passes($untyped, ['meta' => (object) ['a' => 1]]));
        $this->assertFalse($this->passes($typed, ['meta' => (object) ['a' => (object) []]]));
    }

    public function testClosedObjectsAcceptNumericPropertyNames(): void
    {
        $schema = JsonSchema::object(fn ($s) => [
            'meta' => $s->object(fn ($s) => ['0' => $s->string()])->withoutAdditionalProperties(),
        ]);

        $this->assertTrue(Validator::make(['meta' => ['0' => 'a']], JsonSchema::rules($schema))->passes());
        $this->assertFalse(Validator::make(['meta' => ['0' => 'a', 'admin' => true]], JsonSchema::rules($schema))->passes());
    }

    public function testBooleanAndEmptyItemSchemas(): void
    {
        foreach ([true, []] as $items) {
            $schema = ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => $items]]];

            $this->assertTrue($this->passes($schema, ['tags' => ['', 5]]));
        }

        $schema = ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => false]]];

        $this->assertTrue($this->passes($schema, ['tags' => []]));
        $this->assertFalse($this->passes($schema, ['tags' => ['a']]));

        $this->expectException(TypeError::class);

        JsonSchema::rules(['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => null]]]);
    }

    public function testUniqueItemsRefuseRepeatedNullsAndBlankStrings(): void
    {
        $schema = $this->schema(fn ($s) => ['tags' => $s->array()->items($s->string()->nullable())->unique()->required()]);

        $this->assertFalse($this->passes($schema, ['tags' => [null, null]]));
        $this->assertTrue($this->passes($schema, ['tags' => [null, 'a']]));

        $schema = ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'uniqueItems' => true]]];

        $this->assertFalse($this->passes($schema, ['tags' => ['', '']]));
        $this->assertFalse($this->passes($schema, ['tags' => [' ', ' ']]));
        $this->assertTrue($this->passes($schema, ['tags' => ['', ' ']]));
    }

    public function testAnOptionalAnyOfAcceptsAnEmptyArrayBranch(): void
    {
        $optional = $this->schema(fn ($s) => ['v' => $s->anyOf([$s->array()->items($s->string()), $s->string()])]);
        $required = $this->schema(fn ($s) => ['v' => $s->anyOf([$s->array()->items($s->string()), $s->string()])->required()]);

        $this->assertTrue($this->passes($optional, ['v' => []]));
        $this->assertFalse($this->passes($required, ['v' => []]));
    }

    public function testWhitespaceOnlyStringsAreBlank(): void
    {
        $schema = $this->schema(fn ($s) => [
            'note' => $s->string(),
            'seats' => $s->integer(),
            'code' => $s->string()->pattern('x'),
            'plan' => $s->anyOf([$s->string()->enum(['Basic']), $s->integer()])->nullable(),
        ]);

        $this->assertTrue($this->passes($schema, []));

        foreach (['note', 'seats', 'code', 'plan'] as $field) {
            $this->assertFalse($this->passes($schema, [$field => '   ']), $field);
        }
    }

    public function testKeywordsBesideAnyOfAlsoApply(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'size' => ['type' => 'integer', 'maximum' => 10, 'anyOf' => [['type' => 'integer'], ['type' => 'null']]],
            'item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'anyOf' => [['type' => 'object']]],
        ]];

        $this->assertTrue($this->passes($schema, ['size' => 5]));
        $this->assertFalse($this->passes($schema, ['size' => 100]));
        $this->assertFalse($this->passes($schema, ['size' => null]));
        $this->assertTrue($this->passes($schema, ['item' => ['id' => 1]]));
        $this->assertFalse($this->passes($schema, ['item' => ['id' => 'x']]));
        $this->assertFalse($this->passes($schema, ['item' => ['name' => 'x']]));

        $nullable = ['type' => 'object', 'properties' => [
            'size' => ['type' => ['integer', 'null'], 'anyOf' => [['type' => 'integer'], ['type' => 'null']]],
            'nothing' => ['type' => 'null', 'anyOf' => [['type' => 'string'], ['type' => 'null']]],
        ]];

        $this->assertTrue($this->passes($nullable, ['size' => null, 'nothing' => null]));
        $this->assertFalse($this->passes($nullable, ['nothing' => 'x']));
    }

    #[DataProvider('unconvertibleSchemas')]
    public function testRecognizedAssertionsThatCannotBeConvertedAreRefused(array $schema, string $keyword, string $pointer): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs("The JSON Schema keyword [{$keyword}] at [{$pointer}] cannot be converted to validation rules.");

        JsonSchema::rules($schema);
    }

    public static function unconvertibleSchemas(): array
    {
        $object = fn (array $properties) => ['type' => 'object', 'properties' => $properties];

        return [
            'reference' => [$object(['age' => ['$ref' => '#/$defs/age']]) + ['$defs' => ['age' => ['type' => 'integer']]], '$ref', '#/properties/age'],
            'one of in items' => [$object(['list' => ['type' => 'array', 'items' => ['oneOf' => [['type' => 'string']]]]]), 'oneOf', '#/properties/list/items'],
            'const in a branch' => [$object(['kind' => ['anyOf' => [['type' => 'string', 'const' => 'a'], ['type' => 'integer']]]]), 'const', '#/properties/kind/anyOf/0'],
            'const in a null branch' => [$object(['kind' => ['anyOf' => [['type' => 'null', 'const' => null], ['type' => 'string']]]]), 'const', '#/properties/kind/anyOf/0'],
            'escaped pointer' => [$object(['a/b~c' => ['type' => 'object', 'maxProperties' => 1]]), 'maxProperties', '#/properties/a~1b~0c'],
            'schema-valued additional properties' => [$object(['meta' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]), 'additionalProperties', '#/properties/meta'],
            'object-valued additional properties' => [$object(['meta' => ['type' => 'object', 'additionalProperties' => (object) ['type' => 'string']]]), 'additionalProperties', '#/properties/meta'],
            'tuple items' => [$object(['pair' => ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]]]), 'items', '#/properties/pair'],
            'root any of' => [['anyOf' => [['type' => 'object']]], 'anyOf', '#'],
            'root min properties' => [$object([]) + ['minProperties' => 1], 'minProperties', '#'],
            'root string type' => [['type' => 'string'], 'type', '#'],
        ];
    }

    public function testTypeSpecificKeywordsRequireAType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The JSON Schema keyword [format] at [#/properties/email] requires a [type] to be converted to validation rules.');

        JsonSchema::rules(['type' => 'object', 'properties' => ['email' => ['format' => 'email']]]);
    }

    public function testAnnotationsUnknownFormatsAndObjectRootsAreAccepted(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$defs' => ['unused' => ['type' => 'string']],
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => [
                'host' => ['format' => 'hostname', '$comment' => 'Checked elsewhere.', 'x-example' => 'hypervel.org'],
                'meta' => ['type' => 'object', 'additionalProperties' => (object) []],
            ],
        ];

        $this->assertTrue($this->passes($schema, ['host' => 'not a hostname', 'meta' => ['any' => 1], 'extra' => true]));
        $this->assertTrue($this->passes(['properties' => ['seats' => ['type' => 'integer']]], ['seats' => 2]));
    }

    public function testUpstreamShapedHooksAreUsed(): void
    {
        $rules = new class extends ValidationRules {
            public static array $called = [];

            protected function typeRules(?string $type, array $schema): array
            {
                self::$called[] = 'typeRules';

                return parent::typeRules($type, $schema);
            }

            protected function arrayRules(array $schema): array
            {
                self::$called[] = 'arrayRules';

                return [...parent::arrayRules($schema), 'max:5'];
            }

            protected function anyOf(array $schemas): MatchesAnyOf
            {
                self::$called[] = 'anyOf';

                return parent::anyOf($schemas);
            }
        };

        $converted = $rules::for($this->schema(fn ($s) => ['tags' => $s->array()->required(), 'plan' => $s->anyOf([$s->string(), $s->integer()])]));

        $this->assertSame(['required', 'list', 'max:5'], $converted['tags']);
        $this->assertEqualsCanonicalizing(['typeRules', 'arrayRules', 'anyOf'], array_unique($rules::$called));
    }

    public function testDeeplyNestedCompositionsConvertInOnePass(): void
    {
        $branch = ['type' => 'string'];

        for ($depth = 0; $depth < 300; ++$depth) {
            $branch = ['anyOf' => [$branch, ['type' => 'integer']]];
        }

        $schema = ['type' => 'object', 'properties' => ['value' => $branch], 'required' => ['value']];

        $this->assertTrue($this->passes($schema, ['value' => 'x']));
        $this->assertFalse($this->passes($schema, ['value' => []]));
    }

    /**
     * Build an object schema array from the given properties.
     *
     * @return array<string, mixed>
     */
    protected function schema(Closure $properties): array
    {
        return (new ObjectType($properties(new JsonSchemaTypeFactory)))->toArray();
    }

    /**
     * Build an object schema array with additional properties disabled on every nested object.
     *
     * @return array<string, mixed>
     */
    protected function strict(Closure $properties): array
    {
        return $this->withoutAdditionalProperties($this->schema($properties));
    }

    /**
     * Recursively set "additionalProperties" to false on every object schema.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function withoutAdditionalProperties(array $schema): array
    {
        if (in_array('object', (array) ($schema['type'] ?? []), true)) {
            $schema['additionalProperties'] = false;

            foreach ($schema['properties'] ?? [] as $key => $property) {
                $schema['properties'][$key] = $this->withoutAdditionalProperties($property);
            }
        }

        if (isset($schema['items'])) {
            $schema['items'] = $this->withoutAdditionalProperties($schema['items']);
        }

        foreach ($schema['anyOf'] ?? [] as $key => $branch) {
            $schema['anyOf'][$key] = $this->withoutAdditionalProperties($branch);
        }

        return $schema;
    }

    /**
     * Determine if the data passes the rules derived from the schema.
     *
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $data
     */
    protected function passes(array $schema, array $data): bool
    {
        return Validator::make($data, JsonSchema::rules($schema))->passes();
    }
}
