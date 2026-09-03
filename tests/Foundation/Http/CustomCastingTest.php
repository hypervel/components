<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use ArrayObject;
use Carbon\CarbonInterface;
use Hypervel\Foundation\Http\Casts\AsEnumArrayObject;
use Hypervel\Foundation\Http\Casts\AsEnumCollection;
use Hypervel\Foundation\Http\Contracts\CastInputs;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Routing\Redirector;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Json;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rule;
use Hypervel\Validation\ValidationException;
use JsonException;
use stdClass;

class CustomCastingTest extends TestCase
{
    /**
     * Test enum casting.
     */
    public function testEnumCasting()
    {
        $request = EnumCastingRequest::create('/', 'POST', ['status' => 'active']);
        $request->setContainer($this->app);
        $request->validateResolved();

        $status = $request->casted('status');
        $this->assertInstanceOf(UserStatus::class, $status);
        $this->assertSame(UserStatus::Active, $status);
    }

    /**
     * Test enum casting for all data.
     */
    public function testEnumCastingAll()
    {
        $request = EnumCastingRequest::create('/', 'POST', ['status' => 'active', 'name' => 'Test']);
        $request->setContainer($this->app);

        // Use validate = false to avoid validation issues
        $data = $request->casted(null, false);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertInstanceOf(UserStatus::class, $data['status']);
        $this->assertSame(UserStatus::Active, $data['status']);
        $this->assertSame('Test', $data['name']);
    }

    /**
     * Test custom class casting.
     */
    public function testCustomClassCasting()
    {
        $request = CustomClassCastingRequest::create('/', 'POST', ['price' => '1000']);
        $request->setContainer($this->app);

        $price = $request->casted('price', false);
        $this->assertInstanceOf(Money::class, $price);
        $this->assertSame(1000, $price->amount);
        $this->assertSame('TWD', $price->currency);
    }

    /**
     * Test null value handling.
     */
    public function testNullValueHandling()
    {
        $request = NullableEnumCastingRequest::create('/', 'POST', ['status' => null]);
        $request->setContainer($this->app);

        $status = $request->casted('status', false);
        $this->assertNull($status);
    }

    /**
     * Test non-existent field.
     */
    public function testNonExistentField()
    {
        $request = EnumCastingRequest::create('/', 'POST', ['status' => 'active', 'name' => 'Test']);
        $request->setContainer($this->app);
        $request->validateResolved();

        $nonExistent = $request->casted('non_existent');
        $this->assertNull($nonExistent);
    }

    /**
     * Test AsEnumArrayObject casting.
     */
    public function testAsEnumArrayObjectCasting()
    {
        $request = EnumArrayObjectCastingRequest::create('/', 'POST', ['statuses' => ['active', 'inactive']]);
        $request->setContainer($this->app);

        $statuses = $request->casted('statuses', false);
        $this->assertInstanceOf(ArrayObject::class, $statuses);
        $this->assertCount(2, $statuses);
        $this->assertSame(UserStatus::Active, $statuses[0]);
        $this->assertSame(UserStatus::Inactive, $statuses[1]);
    }

    /**
     * Test AsEnumCollection casting.
     */
    public function testAsEnumCollectionCasting()
    {
        $request = EnumCollectionCastingRequest::create('/', 'POST', ['statuses' => ['active', 'inactive', 'pending']]);
        $request->setContainer($this->app);

        $statuses = $request->casted('statuses', false);
        $this->assertInstanceOf(Collection::class, $statuses);
        $this->assertCount(3, $statuses);

        $values = $statuses->pluck('value')->all();
        $this->assertSame(['active', 'inactive', 'pending'], $values);
    }

    /**
     * Test casted($key, false) uses raw input.
     */
    public function testCastedWithoutValidation()
    {
        $request = EnumCastingRequest::create('/', 'POST', ['status' => 'active', 'extra_field' => 'extra_value']);
        $request->setContainer($this->app);

        // Using validate = false should get data from raw input
        $status = $request->casted('status', false);
        $this->assertInstanceOf(UserStatus::class, $status);
        $this->assertSame(UserStatus::Active, $status);

        // Get all casted data from raw input
        $data = $request->casted(null, false);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('extra_field', $data);
    }

    /**
     * Test primitive type casting - int.
     */
    public function testPrimitiveIntCasting()
    {
        $request = PrimitiveCastingRequest::create('/', 'POST', ['age' => '25']);
        $request->setContainer($this->app);

        $age = $request->casted('age', false);
        $this->assertIsInt($age);
        $this->assertSame(25, $age);
    }

    /**
     * Test primitive type casting - float.
     */
    public function testPrimitiveFloatCasting()
    {
        $request = PrimitiveCastingRequest::create('/', 'POST', ['price' => '19.99']);
        $request->setContainer($this->app);

        $price = $request->casted('price', false);
        $this->assertIsFloat($price);
        $this->assertSame(19.99, $price);
    }

    /**
     * Test primitive type casting - bool.
     */
    public function testPrimitiveBoolCasting()
    {
        $request = PrimitiveCastingRequest::create('/', 'POST', ['is_active' => '1']);
        $request->setContainer($this->app);

        $isActive = $request->casted('is_active', false);
        $this->assertIsBool($isActive);
        $this->assertTrue($isActive);
    }

    /**
     * Test primitive type casting - array.
     */
    public function testPrimitiveArrayCasting(): void
    {
        $request = PrimitiveCastingRequest::create('/', 'POST', ['tags' => '["tag1","tag2"]']);
        $request->setContainer($this->app);
        $request->validateResolved();

        $tags = $request->casted('tags');
        $this->assertIsArray($tags);
        $this->assertSame(['tag1', 'tag2'], $tags);
    }

    /**
     * Test primitive type casting - collection.
     */
    public function testPrimitiveCollectionCasting(): void
    {
        $request = PrimitiveCastingRequest::create('/', 'POST', ['items' => '["item1","item2"]']);
        $request->setContainer($this->app);
        $request->validateResolved();

        $items = $request->casted('items');
        $this->assertInstanceOf(Collection::class, $items);
        $this->assertSame(['item1', 'item2'], $items->all());
    }

    public function testPrimitiveJsonCastsUseValidatedJsonStringsAtTheSupportNestingLimit(): void
    {
        $value = 'leaf';

        for ($index = 0; $index < Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $json = Json::encode($value);
        $request = PrimitiveCastingRequest::create('/', 'POST', [
            'tags' => $json,
            'items' => $json,
            'metadata' => $json,
            'settings' => $json,
        ]);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame($value, $request->casted('tags'));
        $this->assertSame($value, $request->casted('metadata'));
        $this->assertSame($value, $request->casted('items')->all());
        $settings = $request->casted('settings');
        $this->assertInstanceOf(stdClass::class, $settings);
        $this->assertEquals(Json::decode($json, assoc: false), $settings);
    }

    public function testPrimitiveJsonCastsRejectMalformedEmptyAndOverDepthRawInput(): void
    {
        $value = 'leaf';

        for ($index = 0; $index <= Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $overDepth = json_encode($value, JSON_THROW_ON_ERROR, Json::MAXIMUM_NESTING_DEPTH + 1);

        foreach (['{invalid', '', $overDepth] as $json) {
            foreach (['tags', 'items', 'settings'] as $key) {
                $request = PrimitiveCastingRequest::create('/', 'POST', [$key => $json]);
                $request->setContainer($this->app);

                $this->assertThrows(
                    fn () => $request->casted($key, false),
                    JsonException::class,
                );
            }
        }
    }

    public function testValidatedJsonCastsRejectOneLevelOverTheSupportNestingLimit(): void
    {
        $value = 'leaf';

        for ($index = 0; $index <= Json::MAXIMUM_NESTING_DEPTH; ++$index) {
            $value = ['value' => $value];
        }

        $request = PrimitiveCastingRequest::create('/', 'POST', [
            'tags' => json_encode($value, JSON_THROW_ON_ERROR, Json::MAXIMUM_NESTING_DEPTH + 1),
        ]);
        $request->setContainer($this->app)
            ->setRedirector($this->app->make(Redirector::class));

        $this->expectException(ValidationException::class);

        $request->validateResolved();
    }

    /**
     * Test primitive type casting - datetime.
     */
    public function testPrimitiveDatetimeCasting(): void
    {
        $request = DatetimeCastingRequest::create('/', 'POST', [
            'created_at' => 1705315800, // 2024-01-15 10:50:00 UTC
            'published_date' => '2024-01-15',
            'updated_timestamp' => '2024-01-15 10:50:00',
        ]);
        $request->setContainer($this->app);

        // Test datetime casting
        $createdAt = $request->casted('created_at', false);
        $this->assertInstanceOf(CarbonInterface::class, $createdAt);
        $this->assertSame(CarbonImmutable::class, $createdAt::class);
        $this->assertSame('2024-01-15 10:50:00', $createdAt->format('Y-m-d H:i:s'));

        // Test date casting (time should be 00:00:00)
        $publishedDate = $request->casted('published_date', false);
        $this->assertInstanceOf(CarbonInterface::class, $publishedDate);
        $this->assertSame(CarbonImmutable::class, $publishedDate::class);
        $this->assertSame('2024-01-15 00:00:00', $publishedDate->format('Y-m-d H:i:s'));

        // Test timestamp casting (returns int timestamp)
        $updatedTimestamp = $request->casted('updated_timestamp', false);
        $this->assertIsInt($updatedTimestamp);
        $this->assertSame(1705315800, $updatedTimestamp);
    }

    public function testPrimitiveDatetimeCastingUsesConfiguredMutableClass(): void
    {
        Date::use(Carbon::class);

        $request = DatetimeCastingRequest::create('/', 'POST', [
            'created_at' => 1705315800,
            'published_date' => '2024-01-15',
            'updated_timestamp' => '2024-01-15 10:50:00',
        ]);
        $request->setContainer($this->app);

        $this->assertSame(Carbon::class, $request->casted('created_at', false)::class);
        $this->assertSame(Carbon::class, $request->casted('published_date', false)::class);
    }

    public function testDatetimeCastingSupportsFormatModifiersAndTrailingData(): void
    {
        $request = DatetimeCastingRequest::create('/', 'POST', [
            'created_at' => '2017-05-11 Y',
        ]);
        $request->useDateFormat('!Y-d-m \Y');

        $createdAt = $request->casted('created_at', false);

        $this->assertSame(CarbonImmutable::class, $createdAt::class);
        $this->assertSame('2017-11-05 00:00:00.000000', $createdAt->format('Y-m-d H:i:s.u'));

        $request = DatetimeCastingRequest::create('/', 'POST', [
            'created_at' => '2020-09-11 trailing data',
        ]);
        $request->useDateFormat('!Y-m-d+');

        $createdAt = $request->casted('created_at', false);

        $this->assertSame(CarbonImmutable::class, $createdAt::class);
        $this->assertSame('2020-09-11 00:00:00.000000', $createdAt->format('Y-m-d H:i:s.u'));
    }

    /**
     * Test datetime casting preserves app timezone for Unix timestamps.
     */
    public function testDatetimeCastingPreservesAppTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $request = DatetimeCastingRequest::create('/', 'POST', [
                'created_at' => 1705315800,
                'published_date' => '2024-01-15',
                'updated_timestamp' => '2024-01-15 10:50:00',
            ]);
            $request->setContainer($this->app);

            $createdAt = $request->casted('created_at', false);
            $this->assertInstanceOf(CarbonInterface::class, $createdAt);
            $this->assertSame('America/New_York', $createdAt->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}

// Test Request Classes

class EnumCastingRequest extends FormRequest
{
    protected array $casts = [
        'status' => UserStatus::class,
    ];

    public function rules(): array
    {
        return [
            'status' => 'required|string',
            'name' => 'string',
        ];
    }
}

class NullableEnumCastingRequest extends FormRequest
{
    protected array $casts = [
        'status' => UserStatus::class,
    ];

    public function rules(): array
    {
        return [
            'status' => 'nullable|string',
        ];
    }
}

class CustomClassCastingRequest extends FormRequest
{
    protected array $casts = [
        'price' => MoneyCast::class,
    ];

    public function rules(): array
    {
        return [
            'price' => 'required|numeric',
        ];
    }
}

class EnumArrayObjectCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'statuses' => AsEnumArrayObject::of(UserStatus::class),
        ];
    }

    public function rules(): array
    {
        return [
            'statuses' => 'required|array',
            'statuses.*' => [Rule::enum(UserStatus::class)],
        ];
    }
}

class EnumCollectionCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'statuses' => AsEnumCollection::of(UserStatus::class),
        ];
    }

    public function rules(): array
    {
        return [
            'statuses' => 'required|array',
            'statuses.*' => [Rule::enum(UserStatus::class)],
        ];
    }
}

class PrimitiveCastingRequest extends FormRequest
{
    protected array $casts = [
        'age' => 'int',
        'price' => 'float',
        'is_active' => 'bool',
        'tags' => 'array',
        'items' => 'collection',
        'metadata' => 'json',
        'settings' => 'object',
    ];

    public function rules(): array
    {
        return [
            'age' => 'numeric',
            'price' => 'numeric',
            'is_active' => 'boolean',
            'tags' => 'json',
            'items' => 'json',
            'metadata' => 'json',
            'settings' => 'json',
        ];
    }
}

class DatetimeCastingRequest extends FormRequest
{
    protected array $casts = [
        'created_at' => 'datetime',
        'published_date' => 'date',
        'updated_timestamp' => 'timestamp',
    ];

    public function useDateFormat(string $format): void
    {
        $this->dateFormat = $format;
    }

    public function rules(): array
    {
        return [
            'created_at' => 'integer',
            'published_date' => 'string',
            'updated_timestamp' => 'string',
        ];
    }
}

// Test Enums and Classes

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency = 'TWD'
    ) {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }
}

class MoneyCast implements CastInputs
{
    public function get(string $key, mixed $value, array $inputs): Money
    {
        return Money::fromCents((int) $value);
    }
}
