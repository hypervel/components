<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use ArrayObject;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Hypervel\Context\Context;
use Hypervel\Foundation\Http\Casts\AsDataObjectArray;
use Hypervel\Foundation\Http\Casts\AsDataObjectCollection;
use Hypervel\Foundation\Http\Casts\AsEnumArrayObject;
use Hypervel\Foundation\Http\Casts\AsEnumCollection;
use Hypervel\Foundation\Http\Contracts\CastInputs;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Collection;
use Hypervel\Support\DataObject;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rule;
use Mockery;
use Psr\Http\Message\ServerRequestInterface;
use Swow\Psr7\Message\ServerRequestPlusInterface;

/**
 * @internal
 * @coversNothing
 */
class CustomCastingTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Test enum casting.
     */
    public function testEnumCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'status' => 'active',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new EnumCastingRequest($this->app);

        $status = $request->casted('status');
        $this->assertInstanceOf(UserStatus::class, $status);
        $this->assertSame(UserStatus::Active, $status);
    }

    /**
     * Test enum casting for all data.
     */
    public function testEnumCastingAll()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'status' => 'active',
            'name' => 'Test',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new EnumCastingRequest($this->app);

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
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'price' => '1000',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new CustomClassCastingRequest($this->app);

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
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'status' => null,
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new NullableEnumCastingRequest($this->app);

        $status = $request->casted('status', false);
        $this->assertNull($status);
    }

    /**
     * Test non-existent field.
     */
    public function testNonExistentField()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'status' => 'active',
            'name' => 'Test',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new EnumCastingRequest($this->app);

        $nonExistent = $request->casted('non_existent');
        $this->assertNull($nonExistent);
    }

    /**
     * Test AsEnumArrayObject casting.
     */
    public function testAsEnumArrayObjectCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'statuses' => ['active', 'inactive'],
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new EnumArrayObjectCastingRequest($this->app);

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
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'statuses' => ['active', 'inactive', 'pending'],
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new EnumCollectionCastingRequest($this->app);

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
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'status' => 'active',
            'extra_field' => 'extra_value',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new EnumCastingRequest($this->app);

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
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'age' => '25',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new PrimitiveCastingRequest($this->app);

        $age = $request->casted('age', false);
        $this->assertIsInt($age);
        $this->assertSame(25, $age);
    }

    /**
     * Test primitive type casting - float.
     */
    public function testPrimitiveFloatCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'price' => '19.99',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new PrimitiveCastingRequest($this->app);

        $price = $request->casted('price', false);
        $this->assertIsFloat($price);
        $this->assertSame(19.99, $price);
    }

    /**
     * Test primitive type casting - bool.
     */
    public function testPrimitiveBoolCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'is_active' => '1',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new PrimitiveCastingRequest($this->app);

        $isActive = $request->casted('is_active', false);
        $this->assertIsBool($isActive);
        $this->assertTrue($isActive);
    }

    /**
     * Test primitive type casting - array.
     */
    public function testPrimitiveArrayCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'tags' => '["tag1","tag2"]',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new PrimitiveCastingRequest($this->app);

        $tags = $request->casted('tags', false);
        $this->assertIsArray($tags);
        $this->assertSame(['tag1', 'tag2'], $tags);
    }

    /**
     * Test primitive type casting - collection.
     */
    public function testPrimitiveCollectionCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'items' => json_encode(['item1', 'item2']),
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new PrimitiveCastingRequest($this->app);

        $items = $request->casted('items', false);
        $this->assertInstanceOf(Collection::class, $items);
        $this->assertSame(['item1', 'item2'], $items->all());
    }

    /**
     * Test primitive type casting - datetime.
     */
    public function testPrimitiveDatetimeCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'created_at' => 1705315800, // 2024-01-15 10:50:00 UTC
            'published_date' => '2024-01-15',
            'updated_timestamp' => '2024-01-15 10:50:00',
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new DatetimeCastingRequest($this->app);

        // Test datetime casting
        $createdAt = $request->casted('created_at', false);
        $this->assertInstanceOf(CarbonInterface::class, $createdAt);
        $this->assertSame('2024-01-15 10:50:00', $createdAt->format('Y-m-d H:i:s'));

        // Test date casting (time should be 00:00:00)
        $publishedDate = $request->casted('published_date', false);
        $this->assertInstanceOf(CarbonInterface::class, $publishedDate);
        $this->assertSame('2024-01-15 00:00:00', $publishedDate->format('Y-m-d H:i:s'));

        // Test timestamp casting (returns int timestamp)
        /** @var Carbon $updatedTimestamp */
        $updatedTimestamp = $request->casted('updated_timestamp', false);
        $this->assertIsInt($updatedTimestamp);
        $this->assertSame(1705315800, $updatedTimestamp);
    }

    /**
     * Test DataObject casting with DataObject.
     */
    public function testDataObjectCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'contact' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new DataObjectCastingRequest($this->app);

        $contact = $request->casted('contact');
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('Jane', $contact->name);
        $this->assertSame('jane@example.com', $contact->email);
    }

    /**
     * Test AsDataObjectArray casting with DataObject.
     */
    public function testAsArrayObjectCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'contacts' => [
                ['name' => 'John', 'email' => 'john@example.com'],
                ['name' => 'Jane', 'email' => 'jane@example.com'],
            ],
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new DataObjectArrayCastingRequest($this->app);

        $contacts = $request->casted('contacts', false);
        $this->assertInstanceOf(ArrayObject::class, $contacts);
        $this->assertCount(2, $contacts);
        $this->assertInstanceOf(Contact::class, $contacts[0]);
        $this->assertSame('John', $contacts[0]->name);
        $this->assertSame('john@example.com', $contacts[0]->email);
    }

    /**
     * Test AsCollection casting with DataObject.
     */
    public function testAsCollectionCasting()
    {
        $psrRequest = Mockery::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([
            'products' => [
                ['sku' => 'ABC123', 'name' => 'Product A', 'price' => 100],
                ['sku' => 'DEF456', 'name' => 'Product B', 'price' => 200],
                ['sku' => 'GHI789', 'name' => 'Product C', 'price' => 150],
            ],
        ]);
        $psrRequest->shouldReceive('getQueryParams')->andReturn([]);
        $psrRequest->shouldReceive('getUploadedFiles')->andReturn([]);
        Context::set(ServerRequestInterface::class, $psrRequest);

        $request = new DataObjectCollectionCastingRequest($this->app);

        $products = $request->casted('products', false);
        $this->assertInstanceOf(Collection::class, $products);
        $this->assertCount(3, $products);
        $this->assertInstanceOf(Product::class, $products->first());

        // Test Collection methods
        $expensiveProducts = $products->filter(fn ($p) => $p->price > 100);
        $this->assertCount(2, $expensiveProducts);

        $skus = $products->pluck('sku')->all();
        $this->assertSame(['ABC123', 'DEF456', 'GHI789'], $skus);
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
    ];

    public function rules(): array
    {
        return [
            'age' => 'numeric',
            'price' => 'numeric',
            'is_active' => 'boolean',
            'tags' => 'string',
            'items' => 'array',
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

    public function rules(): array
    {
        return [
            'created_at' => 'integer',
            'published_date' => 'string',
            'updated_timestamp' => 'string',
        ];
    }
}

class DataObjectCastingRequest extends FormRequest
{
    protected array $casts = [
        'contact' => Contact::class,
    ];

    public function rules(): array
    {
        return [
            'contact' => 'array',
        ];
    }
}

class DataObjectArrayCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'contacts' => AsDataObjectArray::of(Contact::class),
        ];
    }

    public function rules(): array
    {
        return [
            'contacts' => 'array',
        ];
    }
}

class DataObjectCollectionCastingRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'products' => AsDataObjectCollection::of(Product::class),
        ];
    }

    public function rules(): array
    {
        return [
            'products' => 'array',
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

    public function set(string $key, mixed $value, array $inputs): array
    {
        if ($value instanceof Money) {
            return [$key => $value->amount];
        }

        return [$key => $value];
    }
}

class Contact extends DataObject
{
    public function __construct(
        public readonly string $name,
        public readonly string $email
    ) {
    }
}

class Product extends DataObject
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $price
    ) {
    }
}
