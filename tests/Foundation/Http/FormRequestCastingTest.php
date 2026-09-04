<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\FormRequestCastingTest;

use Carbon\Exceptions\InvalidFormatException;
use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Foundation\Http\Casts\AsEnumCollection;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Foundation\Http\InvalidCastException;
use Hypervel\Routing\Redirector;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Exceptions\MathException;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Json;
use Hypervel\Support\ValidatedInput;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class FormRequestCastingTest extends TestCase
{
    /**
     * Test validated and safe extraction returns cast values without mutating request input.
     */
    public function testCastsValidatedAndSafeInputWhileKeepingRequestInputRaw(): void
    {
        $request = $this->validateRequest(ExtractionRequest::class, [
            'age' => '21',
            'status' => 'active',
            'name' => 'Taylor',
        ]);

        $this->assertSame([
            'age' => 21,
            'status' => UserStatus::Active,
            'name' => 'Taylor',
        ], $request->validated());
        $this->assertSame(21, $request->validated('age'));
        $this->assertSame('fallback', $request->validated('missing', 'fallback'));
        $this->assertSame(['age' => 21], $request->safe(['age']));

        $safe = $request->safe();

        $this->assertInstanceOf(ValidatedInput::class, $safe);
        $this->assertSame(UserStatus::Active, $safe->input('status'));
        $this->assertSame('21', $request->input('age'));
        $this->assertSame('21', $request->all()['age']);
        $this->assertSame('21', $request->age);
        $this->assertArrayNotHasKey('missing', $request->validated());
    }

    /**
     * Test exact and generic paths resolve cast declarations consistently.
     */
    public function testExactAndGenericPathsResolveCastsConsistently(): void
    {
        $input = [
            'number' => '12',
            'decimal' => '12.345',
            'date' => '2024-01-15',
            'status' => 'active',
            'custom' => '10',
            'multiplier' => 3,
            'nested' => ['value' => '5'],
        ];
        $exact = $this->validateRequest(ExactCastParityRequest::class, $input)->validated();
        $generic = $this->validateRequest(GenericCastParityRequest::class, $input)->validated();

        foreach (['number', 'decimal', 'status', 'multiplier'] as $key) {
            $this->assertSame($exact[$key], $generic[$key]);
        }

        $this->assertEquals($exact['date'], $generic['date']);
        $this->assertEquals($exact['custom'], $generic['custom']);
        $this->assertSame(5, $generic['nested']['value']);
    }

    /**
     * Test primitive aliases and scalar conversions.
     */
    public function testCastsPrimitiveScalarValues(): void
    {
        $nan = NAN;
        $request = $this->validateRequest(PrimitiveRequest::class, [
            'int_value' => '12',
            'integer_value' => '13',
            'real_value' => '1.25',
            'float_value' => 'Infinity',
            'double_value' => '-Infinity',
            'nan_string' => 'NaN',
            'nan_float' => $nan,
            'string_value' => 14,
            'whole_decimal_value' => '12.5',
            'decimal_value' => '12345678901234567890.125',
        ]);
        $validated = $request->validated();

        $this->assertSame(12, $validated['int_value']);
        $this->assertSame(13, $validated['integer_value']);
        $this->assertSame(1.25, $validated['real_value']);
        $this->assertSame(INF, $validated['float_value']);
        $this->assertSame(-INF, $validated['double_value']);
        $this->assertNan($validated['nan_string']);
        $this->assertNan($validated['nan_float']);
        $this->assertSame('14', $validated['string_value']);
        $this->assertSame('13', $validated['whole_decimal_value']);
        $this->assertSame('12345678901234567890.13', $validated['decimal_value']);
    }

    /**
     * Test decimal casts reject invalid scale declarations.
     */
    #[DataProvider('invalidDecimalCastProvider')]
    public function testDecimalCastsRejectInvalidScaleDeclarations(string $cast): void
    {
        $request = $this->validateRequest(ConfigurableDecimalRequest::class, [
            'value' => '12.5',
            'declaration' => $cast,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The decimal cast for input [value] requires a non-negative integer scale.');

        $request->validated();
    }

    /**
     * Provide invalid decimal cast declarations.
     */
    public static function invalidDecimalCastProvider(): array
    {
        return [
            'missing scale' => ['decimal'],
            'empty scale' => ['decimal:'],
            'non-numeric scale' => ['decimal:foo'],
            'fractional scale' => ['decimal:2.5'],
            'negative scale' => ['decimal:-2'],
            'multiple arguments' => ['decimal:2,3'],
            'out-of-range scale' => ['decimal:' . PHP_INT_MAX . '0'],
        ];
    }

    /**
     * Test boolean casts match the request boolean helper.
     */
    public function testCastsRequestBooleanTokens(): void
    {
        $request = $this->validateRequest(BooleanRequest::class, [
            'one' => '1',
            'true' => 'true',
            'on' => 'on',
            'yes' => 'yes',
            'zero' => '0',
            'false' => 'false',
            'off' => 'off',
            'no' => 'no',
        ]);

        $this->assertSame([
            'one' => true,
            'true' => true,
            'on' => true,
            'yes' => true,
            'zero' => false,
            'false' => false,
            'off' => false,
            'no' => false,
        ], $request->validated());
    }

    /**
     * Test JSON-family casts accept strings and decoded request values.
     */
    public function testCastsJsonArraysCollectionsAndObjects(): void
    {
        $collection = new Collection(['existing']);
        $object = (object) ['existing' => true];
        $request = $this->validateRequest(JsonRequest::class, [
            'array_json' => '["first","second"]',
            'array_decoded' => ['first', 'second'],
            'json_value' => '{"name":"Taylor"}',
            'json_decoded' => ['name' => 'Abigail'],
            'collection_json' => '["first","second"]',
            'collection_decoded' => ['first', 'second'],
            'collection_existing' => $collection,
            'object_json' => '{"profile":{"name":"Taylor"}}',
            'object_decoded' => ['profile' => ['name' => 'Taylor']],
            'object_existing' => $object,
        ]);
        $validated = $request->validated();

        $this->assertSame(['first', 'second'], $validated['array_json']);
        $this->assertSame(['first', 'second'], $validated['array_decoded']);
        $this->assertSame(['name' => 'Taylor'], $validated['json_value']);
        $this->assertSame(['name' => 'Abigail'], $validated['json_decoded']);
        $this->assertSame(['first', 'second'], $validated['collection_json']->all());
        $this->assertSame(['first', 'second'], $validated['collection_decoded']->all());
        $this->assertSame($collection, $validated['collection_existing']);
        $this->assertInstanceOf(stdClass::class, $validated['object_json']);
        $this->assertSame('Taylor', $validated['object_json']->profile->name);
        $this->assertInstanceOf(stdClass::class, $validated['object_decoded']);
        $this->assertInstanceOf(stdClass::class, $validated['object_decoded']->profile);
        $this->assertSame('Taylor', $validated['object_decoded']->profile->name);
        $this->assertSame($object, $validated['object_existing']);
    }

    /**
     * Test JSON casts honor the shared Support nesting limit.
     */
    public function testJsonCastsHonorTheSupportNestingLimit(): void
    {
        $value = 'leaf';

        for ($index = 0; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $json = Json::encode($value);
        $request = $this->validateRequest(JsonRequest::class, [
            'array_json' => $json,
            'collection_json' => $json,
            'object_json' => $json,
        ]);

        $this->assertSame($value, $request->validated('array_json'));
        $this->assertSame($value, $request->validated('collection_json')->all());
        $this->assertEquals(Json::decode($json, assoc: false), $request->validated('object_json'));
    }

    /**
     * Test malformed, empty, and over-depth JSON fails through Support JSON.
     */
    public function testJsonCastsRejectInvalidInput(): void
    {
        $value = 'leaf';

        for ($index = 0; $index <= Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $overDepth = json_encode($value, JSON_THROW_ON_ERROR, Json::MAXIMUM_NESTING_DEPTH + 1);

        foreach (['{invalid', '', $overDepth] as $json) {
            foreach (['array_json', 'collection_json', 'object_json'] as $key) {
                $request = $this->validateRequest(JsonRequest::class, [$key => $json]);

                $this->assertThrows(
                    fn (): mixed => $request->validated($key),
                    JsonException::class,
                );
            }
        }
    }

    /**
     * Test invalid decimal input uses the framework math exception boundary.
     */
    public function testDecimalCastTranslatesMathFailures(): void
    {
        $request = $this->validateRequest(PrimitiveRequest::class, ['decimal_value' => 'invalid']);

        $this->expectException(MathException::class);
        $this->expectExceptionMessage('Unable to cast value to a decimal.');

        $request->validated();
    }

    /**
     * Test primitive and enum nulls are preserved while custom casters receive null.
     */
    public function testNullAndMissingInputBehavior(): void
    {
        $request = $this->validateRequest(NullableRequest::class, [
            'number' => null,
            'status' => null,
            'custom' => null,
            'fallback' => 'present',
        ]);
        $validated = $request->validated();

        $this->assertNull($validated['number']);
        $this->assertNull($validated['status']);
        $this->assertSame('custom:null:present', $validated['custom']);
        $this->assertArrayNotHasKey('missing', $validated);
    }

    /**
     * Test date casts support native values, formats, timestamps, and immutable dates.
     */
    public function testCastsDatesAndTimestamps(): void
    {
        $existing = new DateTimeImmutable('2024-03-04 05:06:07', new DateTimeZone('Asia/Tokyo'));
        $request = $this->validateRequest(DateRequest::class, [
            'date' => '2024-01-15 15:45:30',
            'formatted_date' => '2024-02-03 15:45:30',
            'datetime' => 'January 15 2024 10:50:00 UTC',
            'timestamp' => '1705315800',
            'numeric_string' => '1705315800',
            'year' => '2024',
            'modifier' => '2017-05-11 Y',
            'trailing' => '2020-09-11 trailing data',
            'existing' => $existing,
        ]);
        $validated = $request->validated();

        $this->assertSame(CarbonImmutable::class, $validated['date']::class);
        $this->assertSame('2024-01-15 00:00:00', $validated['date']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-02-03 00:00:00', $validated['formatted_date']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-15 10:50:00', $validated['datetime']->format('Y-m-d H:i:s'));
        $this->assertSame(1705315800, $validated['timestamp']);
        $this->assertSame('2024-01-15 10:50:00', $validated['numeric_string']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-01 00:00:00', $validated['year']->format('Y-m-d H:i:s'));
        $this->assertSame('2017-11-05 00:00:00.000000', $validated['modifier']->format('Y-m-d H:i:s.u'));
        $this->assertSame('2020-09-11 00:00:00.000000', $validated['trailing']->format('Y-m-d H:i:s.u'));
        $this->assertSame(CarbonImmutable::class, $validated['existing']::class);
        $this->assertSame('Asia/Tokyo', $validated['existing']->getTimezone()->getName());
    }

    /**
     * Test timestamp inputs use the application timezone and configured Date class.
     */
    public function testDateCastsHonorTimezoneAndConfiguredDateClass(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        Date::use(Carbon::class);

        try {
            $request = $this->validateRequest(DateRequest::class, [
                'numeric_string' => '1705315800',
                'existing' => new DateTimeImmutable('2024-01-15 10:50:00', new DateTimeZone('UTC')),
            ]);
            $validated = $request->validated();

            $this->assertSame(Carbon::class, $validated['numeric_string']::class);
            $this->assertSame('America/New_York', $validated['numeric_string']->getTimezone()->getName());
            $this->assertSame(Carbon::class, $validated['existing']::class);
        } finally {
            date_default_timezone_set($originalTimezone);
            Date::use(CarbonImmutable::class);
        }
    }

    /**
     * Test formatted date casts reject values that do not match their format.
     */
    public function testFormattedDateCastsRejectMismatchedInput(): void
    {
        $request = $this->validateRequest(DateRequest::class, [
            'formatted_datetime' => 'January 15 2024',
        ]);

        $this->assertThrows(
            fn (): mixed => $request->validated('formatted_datetime'),
            InvalidFormatException::class,
        );
    }

    /**
     * Test backed, integer-backed, unit, wildcard, and collection enum casts.
     */
    public function testCastsEnumsAfterValidation(): void
    {
        $request = $this->validateRequest(EnumRequest::class, [
            'status' => 'active',
            'integer_status' => '1',
            'unit_status' => 'Draft',
            'existing_status' => UserStatus::Inactive,
            'statuses' => ['active', 'inactive'],
            'integer_statuses' => ['1', 2],
        ]);
        $validated = $request->validated();

        $this->assertSame(UserStatus::Active, $validated['status']);
        $this->assertSame(IntegerStatus::Active, $validated['integer_status']);
        $this->assertSame(UnitStatus::Draft, $validated['unit_status']);
        $this->assertSame(UserStatus::Inactive, $validated['existing_status']);
        $this->assertSame([UserStatus::Active, UserStatus::Inactive], $validated['statuses']);
        $this->assertInstanceOf(Collection::class, $validated['integer_statuses']);
        $this->assertSame([IntegerStatus::Active, IntegerStatus::Inactive], $validated['integer_statuses']->all());

        $safe = $request->safe();

        $this->assertTrue($safe->filled('status'));
        $this->assertSame(UserStatus::Active, $safe->enum('status', UserStatus::class));
        $this->assertSame([UserStatus::Active, UserStatus::Inactive], $safe->enums('statuses', UserStatus::class));
        $this->assertSame(
            UserStatus::Active,
            $safe->whenEnum('status', UserStatus::class, static fn (UserStatus $status): UserStatus => $status),
        );
        $this->assertSame('1', $request->input('integer_status'));
    }

    /**
     * Test nested, partial, associative, and escaped wildcard paths preserve output shape.
     */
    public function testCastsNestedAndEscapedPaths(): void
    {
        $request = $this->validateRequest(WildcardRequest::class, [
            'orders' => [
                ['items' => [['price' => '10'], ['price' => '20']]],
                'priority' => ['items' => [['price' => '30']]],
            ],
            'profiles' => [
                'user_one' => ['age' => '31'],
                'admin' => ['age' => '32'],
            ],
            'meta.data' => ['count' => '4'],
            'flags' => ['literal*' => 'yes'],
            'groups' => ['theme.dark' => ['value' => '5']],
        ]);
        $validated = $request->validated();

        $this->assertSame(10, $validated['orders'][0]['items'][0]['price']);
        $this->assertSame(20, $validated['orders'][0]['items'][1]['price']);
        $this->assertSame(30, $validated['orders']['priority']['items'][0]['price']);
        $this->assertSame(31, $validated['profiles']['user_one']['age']);
        $this->assertSame('32', $validated['profiles']['admin']['age']);
        $this->assertSame(4, $validated['meta.data']['count']);
        $this->assertTrue($validated['flags']['literal*']);
        $this->assertSame(5, $validated['groups']['theme.dark']['value']);
        $this->assertArrayNotHasKey('theme', $validated['groups']);
    }

    /**
     * Test wildcard casts preserve unrelated keys containing path characters.
     */
    public function testWildcardCastsPreserveUnrelatedLiteralDotKeys(): void
    {
        $request = $this->validateRequest(ConfigurableCastPathsRequest::class, [
            'cast_declarations' => ['orders.*.price' => 'int'],
            'orders' => [['price' => '5']],
            'meta.data' => ['version' => '1'],
        ]);

        $this->assertSame([
            'orders' => [['price' => 5]],
            'meta.data' => ['version' => '1'],
        ], $request->validated());
    }

    /**
     * Test present cast paths cannot overlap.
     */
    #[DataProvider('overlappingCastPathProvider')]
    public function testRejectsOverlappingCastPaths(array $casts, array $input, string $message): void
    {
        $request = $this->validateRequest(ConfigurableCastPathsRequest::class, [
            'cast_declarations' => $casts,
            ...$input,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $request->validated();
    }

    /**
     * Provide overlapping cast paths.
     */
    public static function overlappingCastPathProvider(): array
    {
        return [
            'parent before child' => [
                ['settings' => 'object', 'settings.rows' => 'int'],
                ['settings' => ['theme' => 'dark', 'rows' => '5']],
                'Cast declarations [settings] and [settings.rows] overlap at input [settings.rows].',
            ],
            'child before parent' => [
                ['settings.rows' => 'int', 'settings' => 'collection'],
                ['settings' => ['theme' => 'dark', 'rows' => '5']],
                'Cast declarations [settings.rows] and [settings] overlap at input [settings].',
            ],
            'wildcard parent and child' => [
                ['orders.*' => 'collection', 'orders.*.price' => 'decimal:2'],
                ['orders' => [['price' => '5.25']]],
                'Cast declarations [orders.*] and [orders.*.price] overlap at input [orders.0.price].',
            ],
            'wildcard and exact duplicate' => [
                ['orders.*.price' => 'decimal:2', 'orders.0.price' => 'int'],
                ['orders' => [['price' => '5']]],
                'Cast declarations [orders.*.price] and [orders.0.price] overlap at input [orders.0.price].',
            ],
            'partial wildcard parent and child' => [
                ['profiles.user_*' => 'object', 'profiles.user_one.age' => 'int'],
                ['profiles' => ['user_one' => ['age' => '31']]],
                'Cast declarations [profiles.user_*] and [profiles.user_one.age] overlap at input [profiles.user_one.age].',
            ],
            'top-level wildcard parent and child' => [
                ['*' => 'object', 'settings.rows' => 'int'],
                ['settings' => ['rows' => '5']],
                'Cast declarations [*] and [settings.rows] overlap at input [settings.rows].',
            ],
            'escaped literal parent and child' => [
                ['meta\.data' => 'object', 'meta\.data.left' => 'int'],
                ['meta.data' => ['left' => '1']],
                'Cast declarations [meta\.data] and [meta\.data.left] overlap at input [meta.data.left].',
            ],
        ];
    }

    /**
     * Test cast paths under the same root remain independent when they do not overlap.
     */
    public function testCastsNonOverlappingPathsUnderTheSameRoot(): void
    {
        $request = $this->validateRequest(ConfigurableCastPathsRequest::class, [
            'cast_declarations' => [
                'orders.*.price' => 'decimal:2',
                'orders.*.quantity' => 'int',
            ],
            'orders' => [
                ['price' => '5.255', 'quantity' => '2'],
                ['price' => '10', 'quantity' => '3'],
            ],
        ]);

        $this->assertSame([
            ['price' => '5.26', 'quantity' => 2],
            ['price' => '10.00', 'quantity' => 3],
        ], $request->validated('orders'));
    }

    /**
     * Test escaped literal paths remain distinct from nested paths.
     */
    public function testCastsEscapedLiteralPathsWithoutFalseOverlaps(): void
    {
        $request = $this->validateRequest(ConfigurableCastPathsRequest::class, [
            'cast_declarations' => [
                'meta\.data.left' => 'int',
                'meta\.data.right' => 'int',
                'meta.data' => 'int',
            ],
            'meta.data' => ['left' => '1', 'right' => '2'],
            'meta' => ['data' => '3'],
        ]);

        $this->assertSame([
            'meta.data' => ['left' => 1, 'right' => 2],
            'meta' => ['data' => 3],
        ], $request->validated());
    }

    /**
     * Test a parent cast does not expose descendant paths absent from the validated input.
     */
    public function testCastPathsMatchTheOriginalValidatedInput(): void
    {
        $request = $this->validateRequest(ConfigurableCastPathsRequest::class, [
            'cast_declarations' => [
                'settings' => 'array',
                'settings.rows' => 'int',
            ],
            'settings' => '{"rows":"5"}',
        ]);

        $this->assertSame(['rows' => '5'], $request->validated('settings'));
    }

    /**
     * Test an unescaped dotted cast does not consume a literal-dot input key.
     */
    public function testUnescapedDottedCastDoesNotConsumeLiteralDotInput(): void
    {
        $request = $this->validateRequest(LiteralDotCollisionRequest::class, [
            'a.b' => '5',
        ]);
        $validated = $request->validated();

        $this->assertSame(['a.b' => '5'], $validated);
        $this->assertArrayNotHasKey('a', $validated);
    }

    /**
     * Test direct and request-castable classes receive arguments and stable sibling input.
     */
    public function testCastsWithCustomClasses(): void
    {
        $request = $this->validateRequest(CustomRequest::class, [
            'price' => '10',
            'class_price' => '20',
            'object_price' => '30',
            'multiplier' => 3,
        ]);
        $validated = $request->validated();

        $this->assertEquals(new Money(30, 'USD', 'price'), $validated['price']);
        $this->assertEquals(new Money(60, 'EUR', 'class_price'), $validated['class_price']);
        $this->assertEquals(new Money(90, 'GBP', 'object_price'), $validated['object_price']);
    }

    /**
     * Test missing custom inputs do not resolve their caster.
     */
    public function testMissingInputDoesNotResolveCustomCaster(): void
    {
        MissingInputCast::$constructions = 0;

        $request = $this->validateRequest(MissingInputRequest::class, ['name' => 'Taylor']);

        $this->assertSame(['name' => 'Taylor'], $request->validated());
        $this->assertSame(0, MissingInputCast::$constructions);
    }

    /**
     * Test casters resolve once per declaration and once again for each extraction.
     */
    public function testReusesCastersWithinOnlyOneExtraction(): void
    {
        DirectCountingCast::$constructions = 0;
        DirectCountingCast::$casts = 0;
        CountingCastable::$resolutions = 0;
        FactoryCountingCast::$constructions = 0;
        FactoryCountingCast::$casts = 0;

        try {
            $request = $this->validateRequest(CountingRequest::class, [
                'items' => [['value' => '1'], ['value' => '2']],
                'factory_items' => [['value' => '3'], ['value' => '4']],
            ]);
            $validated = $request->validated();

            $this->assertSame(1, $validated['items'][0]['value']);
            $this->assertSame('factory:3', $validated['factory_items'][0]['value']);
            $this->assertSame(1, DirectCountingCast::$constructions);
            $this->assertSame(2, DirectCountingCast::$casts);
            $this->assertSame(1, CountingCastable::$resolutions);
            $this->assertSame(1, FactoryCountingCast::$constructions);
            $this->assertSame(2, FactoryCountingCast::$casts);

            $request->safe();

            $this->assertSame(2, DirectCountingCast::$constructions);
            $this->assertSame(4, DirectCountingCast::$casts);
            $this->assertSame(2, CountingCastable::$resolutions);
            $this->assertSame(2, FactoryCountingCast::$constructions);
            $this->assertSame(4, FactoryCountingCast::$casts);
        } finally {
            DirectCountingCast::$constructions = 0;
            DirectCountingCast::$casts = 0;
            CountingCastable::$resolutions = 0;
            FactoryCountingCast::$constructions = 0;
            FactoryCountingCast::$casts = 0;
        }
    }

    /**
     * Test mutable cast results are rebuilt for every extraction.
     */
    public function testDoesNotCacheMutableCastResults(): void
    {
        $request = $this->validateRequest(MutableRequest::class, ['value' => '10']);
        $first = $request->validated('value');
        $first->value = 99;
        $second = $request->validated('value');

        $this->assertNotSame($first, $second);
        $this->assertSame(10, $second->value);
    }

    /**
     * Test undefined caster declarations use request-specific exception details.
     */
    public function testUndefinedCastReportsRequestInputContext(): void
    {
        $request = $this->validateRequest(InvalidRequest::class, ['value' => '10']);

        try {
            $request->validated();
            $this->fail('Expected an invalid request cast exception.');
        } catch (InvalidCastException $exception) {
            $this->assertSame(InvalidRequest::class, $exception->request);
            $this->assertSame('value', $exception->input);
            $this->assertSame('MissingRequestCaster', $exception->castType);
            $this->assertSame(
                'Call to undefined cast [MissingRequestCaster] on input [value] in request [' . InvalidRequest::class . '].',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Test an existing non-caster class is rejected before construction.
     */
    public function testExistingNonCasterClassIsNotConstructed(): void
    {
        NonCaster::$constructions = 0;

        try {
            $request = $this->validateRequest(ExistingNonCasterRequest::class, ['value' => '10']);

            $this->assertThrows(
                fn (): mixed => $request->validated(),
                InvalidCastException::class,
            );
            $this->assertSame(0, NonCaster::$constructions);
        } finally {
            NonCaster::$constructions = 0;
        }
    }

    /**
     * Test enum collection declarations require an enum class.
     */
    public function testEnumCollectionRequiresEnumClass(): void
    {
        $request = $this->validateRequest(InvalidEnumCollectionRequest::class, [
            'statuses' => ['active'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An enum class is required for the FormRequest enum collection cast.');

        $request->validated();
    }

    /**
     * Validate and prepare a form request.
     *
     * @template TRequest of FormRequest
     *
     * @param class-string<TRequest> $class
     * @return TRequest
     */
    protected function validateRequest(string $class, array $input): FormRequest
    {
        $request = $class::create('/', 'POST', $input);
        $request->setContainer($this->app)
            ->setRedirector($this->app->make(Redirector::class));
        $request->validateResolved();

        return $request;
    }
}

class ExtractionRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'age' => 'int',
            'status' => UserStatus::class,
            'missing' => 'int',
        ];
    }

    public function rules(): array
    {
        return [
            'age' => ['required', 'integer'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'name' => ['required', 'string'],
        ];
    }
}

class ExactCastParityRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'number' => 'int',
            'decimal' => 'decimal:2',
            'date' => 'datetime:!Y-m-d',
            'status' => UserStatus::class,
            'custom' => MoneyCast::class . ':USD',
        ];
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'integer'],
            'decimal' => ['required', 'numeric'],
            'date' => ['required', 'string'],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'custom' => ['required', 'numeric'],
            'multiplier' => ['required', 'integer'],
        ];
    }
}

class GenericCastParityRequest extends ExactCastParityRequest
{
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'nested.value' => 'int',
        ];
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'nested' => ['required', 'array'],
            'nested.value' => ['required', 'integer'],
        ];
    }
}

class PrimitiveRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'int_value' => 'int',
            'integer_value' => 'integer',
            'real_value' => 'real',
            'float_value' => 'float',
            'double_value' => 'double',
            'nan_string' => 'real',
            'nan_float' => 'float',
            'string_value' => 'string',
            'whole_decimal_value' => 'decimal:0',
            'decimal_value' => 'decimal:2',
        ];
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->casts()), ['sometimes']);
    }
}

class ConfigurableDecimalRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['value' => (string) $this->input('declaration')];
    }

    public function rules(): array
    {
        return ['value' => ['required']];
    }
}

class BooleanRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'one' => 'bool',
            'true' => 'boolean',
            'on' => 'bool',
            'yes' => 'boolean',
            'zero' => 'bool',
            'false' => 'boolean',
            'off' => 'bool',
            'no' => 'boolean',
        ];
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->casts()), ['sometimes']);
    }
}

class JsonRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'array_json' => 'array',
            'array_decoded' => 'array',
            'json_value' => 'json',
            'json_decoded' => 'json',
            'collection_json' => 'collection',
            'collection_decoded' => 'collection',
            'collection_existing' => 'collection',
            'object_json' => 'object',
            'object_decoded' => 'object',
            'object_existing' => 'object',
        ];
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->casts()), ['sometimes']);
    }
}

class NullableRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'number' => 'int',
            'status' => UserStatus::class,
            'custom' => NullAwareCast::class,
            'missing' => MissingInputCast::class,
        ];
    }

    public function rules(): array
    {
        return [
            'number' => ['nullable'],
            'status' => ['nullable'],
            'custom' => ['nullable'],
            'fallback' => ['required', 'string'],
        ];
    }
}

class DateRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'formatted_date' => 'date:Y-m-d H:i:s',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'numeric_string' => 'datetime',
            'year' => 'datetime:!Y',
            'modifier' => 'datetime:!Y-d-m \Y',
            'trailing' => 'datetime:!Y-m-d+',
            'formatted_datetime' => 'datetime:Y-m-d',
            'existing' => 'datetime',
        ];
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys($this->casts()), ['sometimes']);
    }
}

class EnumRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'integer_status' => IntegerStatus::class,
            'unit_status' => UnitStatus::class,
            'existing_status' => UserStatus::class,
            'statuses.*' => UserStatus::class,
            'integer_statuses' => AsEnumCollection::of(IntegerStatus::class),
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(UserStatus::class)],
            'integer_status' => ['required', Rule::enum(IntegerStatus::class)],
            'unit_status' => ['required'],
            'existing_status' => ['required', Rule::enum(UserStatus::class)],
            'statuses' => ['required', 'array'],
            'statuses.*' => [Rule::enum(UserStatus::class)],
            'integer_statuses' => ['required', 'array'],
            'integer_statuses.*' => [Rule::enum(IntegerStatus::class)],
        ];
    }
}

class WildcardRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'orders.*.items.*.price' => 'int',
            'profiles.user*.age' => 'int',
            'meta\.data.count' => 'int',
            'flags.literal\*' => 'bool',
            'groups.*.value' => 'int',
        ];
    }

    public function rules(): array
    {
        return [
            'orders' => ['required', 'array'],
            'profiles' => ['required', 'array'],
            'meta\.data' => ['required', 'array'],
            'flags' => ['required', 'array'],
            'groups' => ['required', 'array'],
        ];
    }
}

class ConfigurableCastPathsRequest extends FormRequest
{
    protected function casts(): array
    {
        return $this->input('cast_declarations');
    }

    public function rules(): array
    {
        return [
            'settings' => ['sometimes'],
            'orders' => ['sometimes', 'array'],
            'profiles' => ['sometimes', 'array'],
            'meta\.data' => ['sometimes'],
            'meta' => ['sometimes'],
        ];
    }
}

class LiteralDotCollisionRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['a.b' => 'int'];
    }

    public function rules(): array
    {
        return ['a\.b' => ['required']];
    }
}

class CustomRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class . ':USD',
            'class_price' => ClassStringMoney::class . ':EUR',
            'object_price' => ObjectMoney::class . ':GBP',
        ];
    }

    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric'],
            'class_price' => ['required', 'numeric'],
            'object_price' => ['required', 'numeric'],
            'multiplier' => ['required', 'integer'],
        ];
    }
}

class MissingInputRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['missing' => MissingInputCast::class];
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }
}

class CountingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'items.*.value' => DirectCountingCast::class,
            'factory_items.*.value' => CountingCastable::class . ':factory',
        ];
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'factory_items' => ['required', 'array'],
        ];
    }
}

class MutableRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['value' => MutableValueCast::class];
    }

    public function rules(): array
    {
        return ['value' => ['required', 'integer']];
    }
}

class InvalidRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['value' => 'MissingRequestCaster'];
    }

    public function rules(): array
    {
        return ['value' => ['required']];
    }
}

class ExistingNonCasterRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['value' => NonCaster::class];
    }

    public function rules(): array
    {
        return ['value' => ['required']];
    }
}

class InvalidEnumCollectionRequest extends FormRequest
{
    protected function casts(): array
    {
        return ['statuses' => AsEnumCollection::class];
    }

    public function rules(): array
    {
        return ['statuses' => ['required', 'array']];
    }
}

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum IntegerStatus: int
{
    case Active = 1;
    case Inactive = 2;
}

enum UnitStatus
{
    case Draft;
}

class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $input,
    ) {
    }
}

class MoneyCast implements CastsRequestInput
{
    public function __construct(protected readonly string $currency)
    {
    }

    public function cast(string $key, mixed $value, array $input): Money
    {
        return new Money((int) $value * $input['multiplier'], $this->currency, $key);
    }
}

class ClassStringMoney implements RequestCastable
{
    public static function castRequestUsing(array $arguments): string
    {
        return MoneyCast::class;
    }
}

class ObjectMoney implements RequestCastable
{
    public static function castRequestUsing(array $arguments): CastsRequestInput
    {
        return new MoneyCast($arguments[0]);
    }
}

class NullAwareCast implements CastsRequestInput
{
    public function cast(string $key, mixed $value, array $input): string
    {
        return $key . ':' . get_debug_type($value) . ':' . $input['fallback'];
    }
}

class MissingInputCast implements CastsRequestInput
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }

    public function cast(string $key, mixed $value, array $input): mixed
    {
        return $value;
    }
}

class DirectCountingCast implements CastsRequestInput
{
    public static int $constructions = 0;

    public static int $casts = 0;

    public function __construct()
    {
        ++self::$constructions;
    }

    public function cast(string $key, mixed $value, array $input): int
    {
        ++self::$casts;

        return (int) $value;
    }
}

class CountingCastable implements RequestCastable
{
    public static int $resolutions = 0;

    public static function castRequestUsing(array $arguments): string
    {
        ++self::$resolutions;

        return FactoryCountingCast::class;
    }
}

class FactoryCountingCast implements CastsRequestInput
{
    public static int $constructions = 0;

    public static int $casts = 0;

    public function __construct(protected readonly string $prefix)
    {
        ++self::$constructions;
    }

    public function cast(string $key, mixed $value, array $input): string
    {
        ++self::$casts;

        return $this->prefix . ':' . $value;
    }
}

class MutableValue
{
    public function __construct(public int $value)
    {
    }
}

class MutableValueCast implements CastsRequestInput
{
    public function cast(string $key, mixed $value, array $input): MutableValue
    {
        return new MutableValue((int) $value);
    }
}

class NonCaster
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}
