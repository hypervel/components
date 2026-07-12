# Data Objects
[[toc]]

## Introduction

Data Objects provide a powerful and elegant way to create immutable, type-safe data transfer objects in your Hypervel application. They are perfect for handling API responses, configuration data, and any scenario where you need structured data with automatic type conversion and serialization capabilities.

Unlike simple arrays or standard PHP objects, Data Objects offer automatic type casting, property name conversion, nested object resolution, and built-in serialization support while providing array access immutability to prevent accidental modification through array syntax.

Key benefits of Data Objects include:

- **Type Safety**: Automatic conversion between data types with validation
- **Array Access Protection**: Prevents modification through array access syntax (use `readonly` properties for full immutability)
- **Property Mapping**: Automatic conversion between snake_case and camelCase naming conventions
- **Nested Resolution**: Support for automatically resolving nested Data Objects and DateTime instances
- **Serialization**: Built-in JSON and array serialization support

## Basic Usage

### Creating a Data Object

To create a Data Object, extend the `Hypervel\Support\DataObject` class and define your constructor with typed properties:

```php
<?php

use Hypervel\Support\DataObject;

class UserDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public int $age,
        public string $email,
        public ?string $phone = null
    ) {}
}
```

### Creating Instances

Use the static `make` method to create instances from arrays:

```php
$userData = [
    'name' => 'John Doe',
    'age' => '30',        // String will be converted to int
    'email' => 'john@example.com',
    'phone' => null
];

$user = UserDataObject::make($userData);

$user->name;  // 'John Doe'
$user->age;   // 30 (integer)
$user->email; // 'john@example.com'
$user->phone; // null
```

### Property Name Conversion

Data Objects automatically convert between `snake_case` data keys and `camelCase` properties:

```php
class ProductDataObject extends DataObject
{
    public function __construct(
        public string $productName,      // Maps to 'product_name' in array
        public float $unitPrice,         // Maps to 'unit_price' in array
        public bool $isAvailable         // Maps to 'is_available' in array
    ) {}
}

$product = ProductDataObject::make([
    'product_name' => 'Laptop',
    'unit_price' => '999.99',
    'is_available' => 1              // Will be converted to true
]);

$product->productName;  // 'Laptop'
$product->unitPrice;    // 999.99 (float)
$product->isAvailable;  // true (boolean)

$product['product_name'];  // 'Laptop'
$product['unit_price'];    // 999.99 (float)
$product['is_available'];  // true (boolean)
```

## Type Conversion

Data Objects automatically convert data types based on your property declarations:

### Supported Conversions

```php
class TypeConversionExample extends DataObject
{
    public function __construct(
        public string $stringValue,
        public int $intValue,
        public float $floatValue,
        public bool $boolValue,
        public array $arrayValue
    ) {}
}

$data = TypeConversionExample::make([
    'string_value' => 123,           // Converted to '123'
    'int_value' => '42.99',         // Converted to 42 (truncated)
    'float_value' => '3.14159',     // Converted to 3.14159
    'bool_value' => '0',            // Converted to false
    'array_value' => 'single item'  // Wrapped in ['single item']
]);
```

### DateTime Handling

Data Objects provide sophisticated DateTime handling:

```php
class EventDataObject extends DataObject
{
    public function __construct(
        public string $title,
        public DateTime $createdAt
    ) {}
}

$event = EventDataObject::make([
    'title' => 'Conference',
    'created_at' => '2023-12-25 10:00:00'  // Converted to DateTime
], true); // autoResolve = true for DateTime conversion
```

## Nested Data Objects

### Basic Nested Objects

Data Objects support automatic resolution of nested objects when using `autoResolve = true`:

```php
class AddressDataObject extends DataObject
{
    public function __construct(
        public string $street,
        public string $city,
        public string $zipCode
    ) {}
}

class UserDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public AddressDataObject|array|null $address
    ) {}
}

$userData = [
    'name' => 'John Doe',
    'address' => [
        'street' => '123 Main St',
        'city' => 'Boston',
        'zip_code' => '02101'
    ]
];

// Without autoResolve (default)
$user1 = UserDataObject::make($userData);
// $user1->address is an array

// With autoResolve
$user2 = UserDataObject::make($userData, true);
// $user2->address is an AddressDataObject instance
echo $user2->address->street; // '123 Main St'
```

### Deep Nested Objects

AutoResolve works recursively for deeply nested structures:

```php
class CompanyDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public UserDataObject|array $employee
    ) {}
}

$companyData = [
    'name' => 'Acme Corp',
    'employee' => [
        'name' => 'Jane Smith',
        'address' => [
            'street' => '456 Oak Ave',
            'city' => 'Boston',
            'zip_code' => '02101'
        ]
    ]
];

$company = CompanyDataObject::make($companyData, true);

// All nested objects are automatically resolved
$company->employee->name;                // 'Jane Smith'
$company->employee->address->street;     // '456 Oak Ave'
```

## Array Access and Immutability

Data Objects implement `ArrayAccess` for convenient property access while maintaining immutability:

### Reading Properties

```php
$user = UserDataObject::make($userData);

// Both syntaxes work for reading
$user->name;        // Object property access
$user['name'];      // Array access

// Check if property exists
if (isset($user['email'])) {
    echo $user['email'];
}
```

### Array Access Immutability

```php
$user = UserDataObject::make($userData);

// Direct property assignment works (properties are mutable)
$user->name = 'Jane Doe';  // ✅ This works

// Array access is read-only for immutability
$user['name'] = 'Jane Doe';  // ❌ LogicException
unset($user['name']);        // ❌ LogicException
```

::: tip
For true immutability, declare your properties as `readonly`:

```php
class ImmutableUserDataObject extends DataObject
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
        public readonly string $email
    ) {}
}
```
:::

## Serialization

### Array Conversion

Convert Data Objects to arrays for database storage or API responses:

```php
$user = UserDataObject::make($userData, true);
$array = $user->toArray();

// Nested objects are also converted to arrays
print_r($array);
// [
//     'name' => 'John Doe',
//     'address' => [
//         'street' => '123 Main St',
//         'city' => 'Boston',
//         'zip_code' => '02101'
//     ]
// ]
```

### JSON Serialization

Data Objects are automatically JSON serializable:

```php
$user = UserDataObject::make($userData, true);

// Direct JSON encoding
$json = json_encode($user);

// In HTTP responses
return response()->json($user);
```

## Advanced Features

### Custom Property Conversion

Override the default naming convention conversion methods:

```php
class CustomDataObject extends DataObject
{
    public function __construct(
        public string $snake_case_param,
        public string $multi_word_parameter_name
    ) {}

    /**
     * Convert property name to data key format.
     */
    public static function convertPropertyToDataKey(string $input): string
    {
        return Str::camel($input);  // camelCase to camelCase
    }

    /**
     * Convert data key to property name format.
     */
    public static function convertDataKeyToProperty(string $input): string
    {
        return Str::snake($input);  // camelCase to snake_case
    }
}

$object = CustomDataObject::make([
    'snakeCaseParam' => 'foo',           // Maps to snake_case_param
    'multiWordParameterName' => 'bar'    // Maps to multi_word_parameter_name
]);
```

### Custom Dependencies Resolving

You can customize how specific types are resolved when using `autoResolve = true` by overriding the `getCustomizedDependencies` method:

```php
use Money\Money;
use Money\Currency;

class OrderDataObject extends DataObject
{
    public function __construct(
        public string $orderNumber,
        public Money $totalAmount,
        public CustomStatus $status
    ) {}

    /**
     * Define custom resolvers for specific types.
     */
    protected static function getCustomizedDependencies(): array
    {
        return array_merge(parent::getCustomizedDependencies(), [
            // Custom Money object resolver
            Money::class => function ($value) {
                if ($value instanceof Money) {
                    return $value;
                }

                // Expect array with 'amount' and 'currency'
                if (is_array($value)) {
                    return new Money(
                        $value['amount'] ?? 0,
                        new Currency($value['currency'] ?? 'USD')
                    );
                }

                return new Money(0, new Currency('USD'));
            },

            // Custom status enum resolver
            CustomStatus::class => function ($value) {
                if ($value instanceof CustomStatus) {
                    return $value;
                }

                return CustomStatus::from($value);
            },
        ]);
    }
}

$order = OrderDataObject::make([
    'order_number' => 'ORD-12345',
    'total_amount' => [
        'amount' => 2999,  // $29.99 in cents
        'currency' => 'USD'
    ],
    'status' => 'pending'
], true);

$order->totalAmount->getAmount();    // 2999
$order->status->value;               // 'pending'
```

The `getCustomizedDependencies` method returns an array where:

- **Keys** are the fully qualified class names or interface names
- **Values** are callable functions that handle the conversion logic

Built-in dependencies include:
- `DateTimeInterface::class`
- `CarbonInterface::class`
- `DateTime::class`
- `Carbon::class`

::: tip
Custom dependency resolvers are called recursively, so they work with deeply nested Data Objects. Always merge with `parent::getCustomizedDependencies()` to preserve built-in DateTime handling.
:::

### Custom Serializer

You can customize how Data Objects are serialized by overriding the `getSerializers` method. This allows you to control the output format and transform specific properties during serialization:

```php
class ProductDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public Money $price,
        public DateTime $createdAt,
        public array $tags = []
    ) {}

    /**
     * Define custom serializers for specific properties.
     */
    protected function getSerializers(): array
    {
        return array_merge(parent::getSerializers(), [
            // Serialize Money objects as formatted currency
            'price' => function ($value) {
                if ($value instanceof Money) {
                    return [
                        'amount' => $value->getAmount(),
                        'currency' => $value->getCurrency()->getCode(),
                        'formatted' => '$' . number_format($value->getAmount() / 100, 2)
                    ];
                }
                return $value;
            },

            // Format DateTime consistently
            'createdAt' => function ($value) {
                if ($value instanceof DateTime) {
                    return $value->format('c'); // ISO 8601 format
                }
                return $value;
            },

            // Transform tags array
            'tags' => function ($value) {
                if (is_array($value)) {
                    return array_map('strtolower', $value);
                }
                return $value;
            }
        ]);
    }
}

$product = ProductDataObject::make([
    'name' => 'Laptop',
    'price' => new Money(99999, new Currency('USD')), // $999.99
    'created_at' => new DateTime('2023-12-25 10:00:00'),
    'tags' => ['ELECTRONICS', 'COMPUTERS', 'WORK']
], true);

$serialized = $product->toArray();
// [
//     'name' => 'Laptop',
//     'price' => [
//         'amount' => 99999,
//         'currency' => 'USD',
//         'formatted' => '$999.99'
//     ],
//     'created_at' => '2023-12-25T10:00:00+00:00',
//     'tags' => ['electronics', 'computers', 'work']
// ]
```

The `getSerializers` method returns an array where:

- **Keys** are property names (in camelCase format)
- **Values** are callable functions that transform the property value during serialization

Built-in serializers include:
- `CarbonInterface::class`
- `Carbon::class`

> Carbon values will be serialized to ISO 8601 format

::: tip
Custom serializers are applied during `toArray()` and `jsonSerialize()` operations, allowing you to control exactly how your Data Objects appear in API responses and database storage.
:::

### Default Values and Nullable Properties

```php
class UserDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public string $status = 'active',      // Default value
        public ?string $nickname = null,       // Nullable
        public array $preferences = []         // Default array
    ) {}
}

$user = UserDataObject::make([
    'name' => 'John Doe'
    // Other properties will use defaults
]);

$user->status;      // 'active'
$user->nickname;    // null
count($user->preferences); // 0
```

### Refreshing Data Objects

Once you change the property values in data objects, you need to clear internal caches manually to prevent getting old results:

```php
$user = UserDataObject::make([
    'name' => 'Original Name',
    'age' => 30,
]);

$user->toArray(); // This will cache the result

// Modify properties
$user->name = 'Updated Name';
$user->age = 35;

// Refresh internal caches
$user->refresh();
```

### Validation and Error Handling

Data Objects throw exceptions for missing required properties:

```php
try {
    $user = UserDataObject::make([
        // Missing required 'name' property
        'age' => 30
    ]);
} catch (RuntimeException $e) {
    echo $e->getMessage();
    // "Missing required property `name` in `App\DataObjects\UserDataObject`"
}
```

### Eloquent Model Integration

Use the `AsDataObject` cast to automatically convert JSON database columns to Data Objects in your Eloquent models:

```php
use Hypervel\Database\Eloquent\Casts\AsDataObject;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'profile' => AsDataObject::castUsing(UserProfileDataObject::class),
            'settings' => AsDataObject::castUsing(UserSettingsDataObject::class),
            'address' => AsDataObject::castUsing(AddressDataObject::class),
        ];
    }
}
```

**Using the cast in practice:**

```php
// Create a user with Data Object attributes
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'profile' => [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'bio' => 'Software Developer',
        'avatar' => 'avatar.jpg'
    ],
    'settings' => [
        'email_notifications' => true,
        'sms_notifications' => false,
        'timezone' => 'America/New_York',
        'theme' => 'dark'
    ]
]);

// Access as Data Objects with full type safety
$user->profile->firstName;        // 'John'
$user->profile->lastName;         // 'Doe'
$user->settings->emailNotifications; // true
$user->settings->timezone;        // 'America/New_York'

// Modify Data Object properties
$user->settings->theme = 'light';
$user->profile->bio = 'Senior Software Developer';
$user->save();

// Database storage
// The 'profile' column stores: {"first_name":"John","last_name":"Doe","bio":"Senior Software Developer","avatar":"avatar.jpg"}
// The 'settings' column stores: {"email_notifications":true,"sms_notifications":false,"timezone":"America/New_York","theme":"light"}
```

**Migration for JSON columns:**

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->json('profile')->nullable();
    $table->json('settings')->nullable();
    $table->json('address')->nullable();
    $table->timestamps();
});
```

**Benefits of using `AsDataObject` cast:**

- **Automatic serialization/deserialization**: Arrays are automatically converted to/from Data Objects
- **Type safety**: Full IDE support and type checking for nested data
- **AutoResolve enabled**: Nested Data Objects and DateTime instances are automatically resolved
- **Seamless ORM integration**: Works transparently with Eloquent's attribute system

::: tip
The `AsDataObject` cast automatically enables `autoResolve = true`, so nested Data Objects and DateTime instances are automatically resolved from the JSON data.
:::

## Best Practices

### Use Type Declarations

Always use proper type declarations for better type safety and IDE support:

```php
// ✅ Good - explicit types
public function __construct(
    public string $name,
    public int $age,
    public ?DateTime $birthDate = null
) {}

// ❌ Avoid - no type declarations
public function __construct(
    public $name,
    public $age,
    public $birthDate = null
) {}
```

### Organize Related Data Objects

Group related Data Objects in dedicated directories:

```
app/
├── DataObjects/
│   ├── User/
│   │   ├── UserDataObject.php
│   │   ├── AddressDataObject.php
│   │   └── PreferencesDataObject.php
│   └── Product/
│       ├── ProductDataObject.php
│       └── CategoryDataObject.php
```

### Use AutoResolve Judiciously

Enable `autoResolve` only when you need nested object resolution:

```php
// For simple data transfer
$user = UserDataObject::make($data);           // autoResolve = false

// For complex nested structures
$user = UserDataObject::make($data, true);     // autoResolve = true
```

### Handle DateTime Formats Consistently

Define a consistent DateTime format across your application:

```php
class BaseDataObject extends DataObject
{
    protected string $dateFormat = 'Y-m-d H:i:s';
}

// Extend from BaseDataObject for consistency
class UserDataObject extends BaseDataObject
{
    // ...
}
```