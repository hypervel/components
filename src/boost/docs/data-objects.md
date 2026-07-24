# Data Objects

- [Introduction](#introduction)
- [Creating Data Objects](#creating-data-objects)
    - [Creating Instances](#creating-instances)
    - [Property Name Conversion](#property-name-conversion)
- [Type Conversion](#type-conversion)
    - [Date and Time Values](#date-and-time-values)
    - [Nested Data Objects](#nested-data-objects)
    - [Backed Enums](#backed-enums)
- [Array Access](#array-access)
- [Serialization](#serialization)
    - [Converting to Arrays](#converting-to-arrays)
    - [JSON Serialization](#json-serialization)
    - [Custom Serializers](#custom-serializers)
- [Updating Data Objects](#updating-data-objects)
- [Customizing Data Objects](#customizing-data-objects)
    - [Custom Property Conversion](#custom-property-conversion)
    - [Custom Dependency Resolution](#custom-dependency-resolution)
    - [Auto-Casting](#auto-casting)
    - [Flushing State](#flushing-state)
- [Form Request Casting](#form-request-casting)
- [Eloquent Casting](#eloquent-casting)
- [Validation and Exceptions](#validation-and-exceptions)

<a name="introduction"></a>
## Introduction

Hypervel data objects provide a small, typed wrapper around array data. They are useful when you want to pass structured data through your application without repeatedly reading from untyped arrays.

Data objects support constructor-promoted properties, automatic scalar casting, conversion between `snake_case` array keys and `camelCase` property names, nested object resolution, array access for reads, and array / JSON serialization.

> [!NOTE]
> Data objects are not fully immutable by default. Array access is read-only, but public properties may still be assigned unless you declare them as `readonly`.

<a name="creating-data-objects"></a>
## Creating Data Objects

To create a data object, extend the `Hypervel\Support\DataObject` class and define the values your object accepts on its constructor:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class UserData extends DataObject
{
    public function __construct(
        public string $name,
        public int $age,
        public string $email,
        public ?string $phone = null,
    ) {
    }
}
```

If you want the object itself to be immutable, use `readonly` properties:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class UserData extends DataObject
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
        public readonly string $email,
    ) {
    }
}
```

<a name="creating-instances"></a>
### Creating Instances

You may create data object instances using the `make` method. The `from` method is also available as an alias:

```php
$user = UserData::make([
    'name' => 'Taylor Otwell',
    'age' => '39',
    'email' => 'taylor@example.com',
]);

$user = UserData::from([
    'name' => 'Taylor Otwell',
    'age' => '39',
    'email' => 'taylor@example.com',
]);
```

When auto-casting is enabled, scalar constructor arguments are cast to the declared type:

```php
$user->age;

// 39
```

Missing values use their constructor defaults. Passing `null` explicitly is distinct from omitting a nullable value.

<a name="property-name-conversion"></a>
### Property Name Conversion

Data objects convert between `snake_case` array keys and `camelCase` properties:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class ProductData extends DataObject
{
    public function __construct(
        public string $productName,
        public float $unitPrice,
        public bool $isAvailable,
    ) {
    }
}
```

The `product_name`, `unit_price`, and `is_available` array keys are mapped to the constructor properties:

```php
$product = ProductData::make([
    'product_name' => 'Desk',
    'unit_price' => '199.99',
    'is_available' => 1,
]);

$product->productName;

// Desk

$product['unit_price'];

// 199.99
```

<a name="type-conversion"></a>
## Type Conversion

Data objects automatically cast values for constructor parameters typed as `string`, `int`, `float`, `bool`, or `array`:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class TypeConversionData extends DataObject
{
    public function __construct(
        public string $stringValue,
        public int $integerValue,
        public float $floatValue,
        public bool $booleanValue,
        public array $arrayValue,
    ) {
    }
}
```

```php
$data = TypeConversionData::make([
    'string_value' => 123,
    'integer_value' => '42',
    'float_value' => '3.14',
    'boolean_value' => 1,
    'array_value' => 'single item',
]);
```

<a name="date-and-time-values"></a>
### Date and Time Values

When the second argument passed to `make` is `true`, data objects will resolve supported object dependencies. The built-in date resolver supports `DateTimeInterface`, `Carbon\CarbonInterface`, native `DateTime` and `DateTimeImmutable`, `Hypervel\Support\Carbon` and `Hypervel\Support\CarbonImmutable`, and Carbon's base mutable and immutable classes.

Interface-typed properties use Hypervel's configured date factory and therefore receive an exact `Hypervel\Support\CarbonImmutable` instance by default. A concrete property type always receives that exact concrete class, regardless of the configured factory. This allows a data object to request mutable or immutable behavior explicitly while keeping interfaces application-configurable:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use DateTimeInterface;
use Hypervel\Support\DataObject;

class EventData extends DataObject
{
    public function __construct(
        public string $title,
        public DateTimeInterface $startsAt,
    ) {
    }
}
```

```php
$event = EventData::make([
    'title' => 'Conference',
    'starts_at' => '2026-04-30 09:00:00',
], autoResolve: true);
```

By default, database-style date strings are parsed using the `Y-m-d H:i:s` format. You may customize the format by defining a static `$dateFormat` property on your data object:

```php
class EventData extends DataObject
{
    protected static string $dateFormat = 'Y-m-d H:i:s.u';

    public function __construct(
        public DateTimeInterface $startsAt,
    ) {
    }
}
```

<a name="nested-data-objects"></a>
### Nested Data Objects

Nested data objects are resolved when `autoResolve` is enabled:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class AddressData extends DataObject
{
    public function __construct(
        public string $street,
        public string $city,
        public string $postalCode,
    ) {
    }
}

class UserData extends DataObject
{
    public function __construct(
        public string $name,
        public AddressData|array|null $address,
    ) {
    }
}
```

```php
$user = UserData::make([
    'name' => 'Taylor Otwell',
    'address' => [
        'street' => '123 Main Street',
        'city' => 'Chicago',
        'postal_code' => '60601',
    ],
], autoResolve: true);

$user->address->street;

// 123 Main Street
```

Nested resolution works recursively for nested data object properties. If you use a union type for an auto-resolved dependency, the union should include a data object or date / time type.

You may also pass an existing nested data object instance. Auto-resolution preserves that instance instead of rebuilding it.

<a name="backed-enums"></a>
### Backed Enums

Backed enums are resolved automatically when `autoResolve` is enabled:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
```

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\OrderStatus;
use Hypervel\Support\DataObject;

class OrderData extends DataObject
{
    public function __construct(
        public string $number,
        public OrderStatus $status,
    ) {
    }
}
```

```php
$order = OrderData::make([
    'number' => 'ORD-1000',
    'status' => 'paid',
], autoResolve: true);

$order->status === OrderStatus::Paid;

// true
```

<a name="array-access"></a>
## Array Access

Data objects implement PHP's `ArrayAccess` interface. Array access uses the serialized array keys, so `camelCase` properties are read using their `snake_case` key:

```php
$product = ProductData::make([
    'product_name' => 'Desk',
    'unit_price' => '199.99',
    'is_available' => true,
]);

$product['product_name'];

// Desk
```

Array access is read-only:

```php
$product['product_name'] = 'Chair';

// LogicException

unset($product['product_name']);

// LogicException
```

<a name="serialization"></a>
## Serialization

<a name="converting-to-arrays"></a>
### Converting to Arrays

The `toArray` method converts a data object to an array using the configured data keys:

```php
$user = UserData::make([
    'name' => 'Taylor Otwell',
    'address' => [
        'street' => '123 Main Street',
        'city' => 'Chicago',
        'postal_code' => '60601',
    ],
], autoResolve: true);

$user->toArray();

// [
//     'name' => 'Taylor Otwell',
//     'address' => [
//         'street' => '123 Main Street',
//         'city' => 'Chicago',
//         'postal_code' => '60601',
//     ],
// ]
```

Nested data objects are recursively converted to arrays. Objects with a `toArray` method are also converted using that method.

<a name="json-serialization"></a>
### JSON Serialization

Data objects implement `JsonSerializable`, so they may be encoded directly:

```php
return response()->json($user);
```

<a name="custom-serializers"></a>
### Custom Serializers

You may customize how object values are serialized by overriding the `getSerializers` method. Serializer keys are class names, and serializers are applied to object values during `toArray` and JSON serialization:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}

class ProductPriceData extends DataObject
{
    public function __construct(
        public string $name,
        public Money $price,
    ) {
    }

    protected static function getSerializers(): array
    {
        return array_merge(parent::getSerializers(), [
            Money::class => fn (Money $money) => [
                'amount' => $money->amount,
                'currency' => $money->currency,
            ],
        ]);
    }
}
```

All `DateTimeInterface` and Carbon instances created by the built-in resolver are serialized as ISO 8601 strings.

<a name="updating-data-objects"></a>
## Updating Data Objects

The `update` method updates properties using serialized array keys and clears the cached array representation:

```php
$product = ProductData::make([
    'product_name' => 'Desk',
    'unit_price' => '199.99',
    'is_available' => true,
]);

$product->toArray();

$product->update([
    'product_name' => 'Chair',
]);

$product->productName;

// Chair
```

If you assign to a public property directly after calling `toArray`, call `refresh` before serializing the object again:

```php
$product->productName = 'Table';

$product->refresh();
```

<a name="customizing-data-objects"></a>
## Customizing Data Objects

<a name="custom-property-conversion"></a>
### Custom Property Conversion

You may customize how data object property names are converted to and from array keys by overriding the `convertPropertyToDataKey` and `convertDataKeyToProperty` methods:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;
use Hypervel\Support\Str;

class ExternalUserData extends DataObject
{
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {
    }

    public static function convertPropertyToDataKey(string $input): string
    {
        return Str::camel($input);
    }

    public static function convertDataKeyToProperty(string $input): string
    {
        return Str::snake($input);
    }
}
```

```php
$user = ExternalUserData::make([
    'firstName' => 'Taylor',
    'lastName' => 'Otwell',
]);
```

<a name="custom-dependency-resolution"></a>
### Custom Dependency Resolution

You may customize how object dependencies are resolved when `autoResolve` is enabled by overriding the `getCustomizedDependencies` method:

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Hypervel\Support\DataObject;

class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}

class OrderData extends DataObject
{
    public function __construct(
        public string $number,
        public Money $total,
    ) {
    }

    protected static function getCustomizedDependencies(): array
    {
        return array_merge(parent::getCustomizedDependencies(), [
            Money::class => fn (array|Money $value) => $value instanceof Money
                ? $value
                : new Money($value['amount'], $value['currency']),
        ]);
    }
}
```

```php
$order = OrderData::make([
    'number' => 'ORD-1000',
    'total' => [
        'amount' => 2999,
        'currency' => 'USD',
    ],
], autoResolve: true);
```

Always merge with `parent::getCustomizedDependencies()` so the built-in date and time resolvers remain available.

<a name="auto-casting"></a>
### Auto-Casting

Auto-casting is enabled by default. You may disable it if you want constructor values to be passed through without scalar type conversion:

```php
DataObject::disableAutoCasting();

DataObject::isAutoCasting();

// false

DataObject::enableAutoCasting();
```

> [!WARNING]
> Auto-casting is controlled by a static flag that persists for the worker lifetime. Configure it during application boot or in tests, not per request.

<a name="flushing-state"></a>
### Flushing State

The `flushState` method clears data object caches and resets auto-casting and the date format to their defaults:

```php
DataObject::flushState();
```

This method is useful in tests or when changing data object configuration during bootstrapping.

<a name="form-request-casting"></a>
## Form Request Casting

Form requests may cast validated input into data objects. To cast a single nested array, use the data object class name as the cast target:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DataObjects\AddressData;
use Hypervel\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'address' => AddressData::class,
        ];
    }
}
```

To cast an array of data objects, use `AsDataObjectArray`. The cast returns an `ArrayObject` containing data object instances:

```php
use App\DataObjects\ContactData;
use Hypervel\Foundation\Http\Casts\AsDataObjectArray;

protected function casts(): array
{
    return [
        'contacts' => AsDataObjectArray::of(ContactData::class),
    ];
}
```

To cast into a collection, use `AsDataObjectCollection`:

```php
use App\DataObjects\ProductData;
use Hypervel\Foundation\Http\Casts\AsDataObjectCollection;

protected function casts(): array
{
    return [
        'products' => AsDataObjectCollection::of(ProductData::class),
    ];
}
```

For more information on request input casting, see the [validation documentation](/docs/{{version}}/validation#casting-form-request-data).

<a name="eloquent-casting"></a>
## Eloquent Casting

The `AsDataObject` cast converts JSON columns into data object instances:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\DataObjects\UserProfileData;
use Hypervel\Database\Eloquent\Casts\AsDataObject;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    protected function casts(): array
    {
        return [
            'profile' => AsDataObject::castUsing(UserProfileData::class),
        ];
    }
}
```

When an Eloquent model retrieves the value, the cast decodes the JSON value and creates the data object with `autoResolve` enabled. When the model is saved, the data object is encoded back to JSON.

```php
$user = User::create([
    'profile' => [
        'first_name' => 'Taylor',
        'last_name' => 'Otwell',
    ],
]);

$user->profile->firstName;

// Taylor
```

For more information on Eloquent casts, see the [Eloquent mutators and casts documentation](/docs/{{version}}/eloquent-mutators#data-object-casting).

<a name="validation-and-exceptions"></a>
## Validation and Exceptions

Data objects do not replace request validation. Validate external input before creating a data object, then use the data object to work with typed values in the rest of your application.

If a required constructor argument is missing and no default value is available, Hypervel will throw a `RuntimeException`:

```php
try {
    $user = UserData::make([
        'age' => 39,
        'email' => 'taylor@example.com',
    ]);
} catch (RuntimeException $e) {
    $e->getMessage();

    // Missing required property `name` in `App\DataObjects\UserData`
}
```
