# Data Objects

- [Introduction](#introduction)
- [Choosing a Base Class](#choosing-a-base-class)
- [Lightweight Data Objects](#lightweight-data-objects)
- [Creating Data Objects](#creating-data-objects)
    - [Creating Instances](#creating-instances)
    - [Associating a Data Class](#associating-a-data-class)
    - [Defaults, Null, and Optional Values](#defaults-null-and-optional-values)
    - [Empty Representations](#empty-representations)
    - [Named Factories](#named-factories)
    - [Preparing Input](#preparing-input)
    - [Property Name Conversion](#property-name-conversion)
- [Type Conversion](#type-conversion)
    - [Date and Time Values](#date-and-time-values)
    - [Nested Data Objects](#nested-data-objects)
    - [Backed Enums](#backed-enums)
- [Casts and Transformers](#casts-and-transformers)
- [Validation](#validation)
    - [Validation Attributes](#validation-attributes)
    - [Manual Rules and Hooks](#manual-rules-and-hooks)
    - [Creation Factories](#creation-factories)
- [Transformation](#transformation)
    - [Lazy Properties](#lazy-properties)
    - [Partial Trees](#partial-trees)
    - [Hidden, Computed, and Appended Values](#hidden-computed-and-appended-values)
- [Collections](#collections)
- [HTTP Resources](#http-resources)
- [Form Request Casting](#form-request-casting)
- [Eloquent Casting](#eloquent-casting)
- [Contextual Constructor Values](#contextual-constructor-values)
- [Inertia](#inertia)
- [Saloon](#saloon)
- [Generating Data Classes](#generating-data-classes)
- [Worker Lifetime](#worker-lifetime)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel Data provides an expressive way to turn arrays, requests, Eloquent models, and other input into typed PHP objects. These objects may validate incoming data, transform values for output, be collected, returned from routes and controllers, and stored with Eloquent.

If you have used Spatie Laravel Data, the package's classes and methods should feel familiar. Hypervel Data provides this familiar API while remaining suitable for Hypervel's long-running workers.

<a name="choosing-a-base-class"></a>
## Choosing a Base Class

The package provides three base classes:

- `Data` supports creation, validation, transformation, HTTP responses, collections, and Eloquent casting.
- `Dto` supports creation and validation without transformation or response behavior. It is a good choice for commands, service boundaries, and domain input.
- `Resource` supports creation, transformation, HTTP responses, collections, and Eloquent casting without the public validation methods.

Choose the base class that provides the behavior your object needs. Since `Dto` does not transform values, nested or collected DTOs remain objects when a surrounding `Data` object is transformed. Use `Data` or `Resource` when nested values should also be transformed.

For trusted internal values that only need typed construction and array or JSON output, consider a [lightweight data object](#lightweight-data-objects).

<a name="lightweight-data-objects"></a>
## Lightweight Data Objects

The `Hypervel\Support\DataObject` class provides a small mapper for internal message envelopes, per-item value objects, and other trusted values used in performance-sensitive code. It does not provide validation, property mapping, lazy properties, partials, resources, or persistence. Use `Data`, `Dto`, or `Resource` when you need those features.

To define a lightweight data object, extend `DataObject` and promote every constructor parameter as a public property:

```php
<?php

declare(strict_types=1);

namespace App\Messages;

use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\DataObject;

final class MessageEnvelope extends DataObject
{
    public function __construct(
        public readonly string $id,
        public readonly MessageType $type,
        public readonly MessagePayload $payload,
        public readonly CarbonImmutable $receivedAt,
        public readonly ?string $traceId = null,
    ) {
    }
}
```

Create the object using `from`:

```php
$message = MessageEnvelope::from([
    'id' => 'msg_01',
    'type' => 'created',
    'payload' => ['name' => 'Taylor'],
    'receivedAt' => '2026-09-05 12:34:56',
]);
```

In this example, `MessageType` is a string-backed enum and `MessagePayload` is another lightweight data object.

Constructor property names are the exact input and output keys. Unknown input keys are ignored, but names are not converted between camel case and snake case. Omitted parameters use their declared defaults, while omitted nullable parameters without a default receive `null`.

Common integer, float, boolean, and string representations are converted strictly. Backed enums, dates, and properties typed as a concrete `DataObject` are also converted. Invalid scalar values throw an `InvalidArgumentException` instead of being silently coerced. Use an application named factory when an external payload needs different names or custom conversion.

Integer-backed enums accept integral numeric values such as `1`, `"1.0"`, and `"1e0"`. Fractional values are rejected instead of being truncated to an enum case.

When a form request owns validation, you may declare a lightweight data object directly in its `casts` method. Use a wildcard to convert each member of a validated list:

```php
protected function casts(): array
{
    return [
        'contact' => Contact::class,
        'contacts.*' => Contact::class,
    ];
}
```

The request returns a `Contact` from `validated('contact')` and an array of `Contact` objects from `validated('contacts')`. The DataObject does not run another validation step.

The `toArray` and `toJson` methods recursively normalize nested data objects, backed enums, dates, and `Arrayable` values. Public properties remain ordinary PHP properties and may be read or changed directly unless they are declared `readonly`.

An `array` property retains its input items as-is during construction. Convert a one-off list explicitly:

```php
$items = array_map(ItemData::from(...), $payload['items']);
```

Use Hypervel Data and `DataCollection` when a reusable typed collection needs validation, mapping, transformation controls, or response behavior.

<a name="creating-data-objects"></a>
## Creating Data Objects

Extend one of the base classes and define the object's public properties:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Hypervel\Data\Data;

class UserData extends Data
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

Constructor-promoted `readonly` properties are supported, including properties promoted by a parent constructor that your constructor calls. Public properties that are not declared by a constructor are assigned after construction and therefore cannot be `readonly`, unless the class sets the value itself as a `#[Computed]` property.

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Hypervel\Data\Dto;

class UserCommand extends Dto
{
    public function __construct(
        public readonly string $name,
        public readonly int $age,
        public readonly string $email,
    ) {
    }
}
```

A constructor parameter without a matching public property, such as a plain parameter or a `protected` promoted property, receives the input value with the same name, unchanged. Name mapping, casts, and inferred validation rules do not apply to it, but rules you declare for it in `rules()` do, and creation and validation hooks may change it. Eloquent models do not supply these values.

```php
use Hypervel\Data\Attributes\Computed;

class SearchData extends Data
{
    #[Computed]
    public string $term;

    public function __construct(string $query)
    {
        $this->term = trim($query);
    }
}

SearchData::from(['query' => '  laravel ']);
```

<a name="creating-instances"></a>
### Creating Instances

Create an object with `from`:

```php
$user = UserData::from([
    'name' => 'Taylor Otwell',
    'age' => '39',
    'email' => 'taylor@example.com',
]);
```

`from` accepts arrays, JSON strings, `Arrayable` objects, initialized public properties from ordinary objects, Eloquent models, and requests. Existing instances of the requested data type pass through unchanged.

A model attribute holding `null` is passed as `null`. Columns that were not selected, and attributes the model refuses to read, are treated as missing, so the property's default, `Optional`, or `null` applies.

You may pass multiple payloads. Later payloads take precedence: for each property, the last payload containing its input key wins, including when that value is `null`. An `Optional` value never replaces a value supplied by an earlier payload:

```php
$user = UserData::from($defaults, $requestValues, $routeValues);
```

Payloads may also be passed as PHP named arguments. Hypervel preserves their names while selecting a named factory. When no factory matches, the payload values are processed in call order, so the same precedence applies.

Use `optional` when the whole object may be absent. It returns `null` when no payload is supplied or every supplied payload is `null`:

```php
$user = UserData::optional($payload);
```

<a name="associating-a-data-class"></a>
### Associating a Data Class

A model, request, or other source object may use the `WithData` trait to expose its associated data object:

```php
use App\Data\UserData;
use Hypervel\Data\WithData;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /** @use WithData<UserData> */
    use WithData;

    protected string $dataClass = UserData::class;
}

$data = $user->getData();
```

You may instead return the class from a `dataClass()` method. The `$dataClass` property takes precedence when both are declared.

When a FormRequest uses `WithData`, `getData()` runs the associated data class's authorization and validation rules. It does not reuse the FormRequest's rules. To construct from the FormRequest's validated result instead, pass `$request->validated()` directly to `UserData::from()` or enable the [`FormRequestNormalizer`](#casts-and-transformers).

<a name="defaults-null-and-optional-values"></a>
### Defaults, Null, and Optional Values

Missing properties resolve in this order: a declared constructor default, an `Optional` union, then `null` for a nullable type. Any other missing property fails validation or construction.

```php
use Hypervel\Data\Optional;

class PatchUserData extends Data
{
    public function __construct(
        public string|Optional $name,
        public ?string $phone,
        public string $locale = 'en',
    ) {
    }
}
```

For this object, an omitted `name` becomes `Optional::create()`, an omitted `phone` becomes `null`, and an omitted `locale` uses `en`. Explicit `null` is a supplied value and is accepted only when the declared type allows it. Use `#[Present]` when a nullable input key must still be supplied.

A public property declared outside the constructor keeps any value the constructor assigned to it when its input is missing or `Optional`; supplied input, including `null`, replaces it. Validation infers its rules from the declaration alone, so when requests may omit such a property, give it a nullable or `Optional` type or add `#[Sometimes]`.

<a name="empty-representations"></a>
### Empty Representations

The `Data` and `Resource` classes can produce an empty output shape without creating an object. Constructor defaults are retained, arrays and collections become empty arrays, nested data objects produce their own empty shape, and scalar values become `null`:

```php
class UserData extends Data
{
    public function __construct(
        public string $name,
        public array $roles,
        public bool $active = true,
    ) {
    }
}

UserData::empty();

// ['name' => null, 'roles' => [], 'active' => true]
```

You may pass replacement values as the first argument, change the value used for otherwise empty properties with `replaceNullValuesWith`, or filter the result using `only` and `except`:

Replacement values are keyed by PHP property name and apply only to properties without a declared default. The `only` and `except` lists use the mapped output names in the empty representation.

```php
UserData::empty(
    ['name' => 'Unknown'],
    except: ['active'],
);
```

<a name="named-factories"></a>
### Named Factories

Public static methods beginning with `from` may provide source-specific construction. Type the parameters so Hypervel can choose the first compatible method in declaration order:

```php
use Hypervel\Database\Eloquent\Model;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }

    public static function fromModel(Model $user): self
    {
        return new self($user->getKey(), $user->getAttribute('name'));
    }
}
```

Named methods may receive dependencies from the service container as well as a `CreationContext`. A named method must return the requested data object, and Hypervel uses it directly without validating, casting, or running creation hooks for it again. This also applies to requests, so a named method that returns the finished object from a request is responsible for validating it. The data class's authorization still runs first. To convert other input into the shape Hypervel reads, use a [normalizer](#casts-and-transformers) or [`prepareForPipeline()`](#preparing-input) instead. Beneath a property with an explicit cast, a named method runs only if the cast declines the value. When the object is validated, validation checks the value against the declared rules first.

During ordinary creation, Hypervel maps each input value to a public property. It cannot determine how one property should be divided among variadic constructor arguments. A private or protected constructor is also unavailable to ordinary creation. In either case, use a named factory that returns the finished object.

You may also define public static methods beginning with `collect` to customize collection creation. These methods receive the source's own array, collection, or paginator shape after its values have been converted to data objects, rather than the original source values. An Eloquent collection source is provided as a base `Hypervel\Support\Collection`. When you request an explicit collection target, the method's declared return type must also match that target.

<a name="preparing-input"></a>
### Preparing Input

Define a static `prepareForPipeline` method when a class needs to reshape its input before its properties are read:

```php
use Hypervel\Support\Arr;

class SongMetadataData extends Data
{
    public function __construct(
        public string $year,
        public string $producer,
    ) {
    }
}

class SongData extends Data
{
    public function __construct(
        public string $title,
        public SongMetadataData $metadata,
    ) {
    }

    public static function prepareForPipeline(array $properties): array
    {
        $properties['metadata'] = Arr::only($properties, ['year', 'producer']);

        return $properties;
    }
}

$song = SongData::from([
    'title' => 'Never Gonna Give You Up',
    'year' => '1987',
    'producer' => 'Stock Aitken Waterman',
]);
```

The method receives each payload separately. When a factory has `prepareData` hooks, they run first, and the method receives their merged result once. An Eloquent model is passed as an array of its declared property values. For a property-morphable class, the method of the selected class is called. A named factory that returns the finished object skips this method.

<a name="property-name-conversion"></a>
### Property Name Conversion

Input and output names are unchanged by default. Use mapping attributes when the wire format differs from the PHP property name:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;

class ProductData extends Data
{
    public function __construct(
        #[MapInputName('product_name'), MapOutputName('product')]
        public string $productName,
        #[MapInputName('unit_price')]
        public float $unitPrice,
    ) {
    }
}
```

The `product_name` and `unit_price` array keys are mapped to the constructor properties:

```php
$product = ProductData::from([
    'product_name' => 'Desk',
    'unit_price' => '199.99',
]);

$product->productName;

// Desk
```

`MapName` applies the same name in both directions. `MapInputName` and `MapOutputName` keep the directions independent. Class-level mappers such as `SnakeCaseMapper`, `CamelCaseMapper`, and `KebabCaseMapper` provide a convention for every property, while a property attribute overrides the class mapper.

Mapped input paths may use dot notation. Validated input must use the mapped input path, which is where its rules apply. Without validation, the PHP property name is read when the mapped input is missing. Hypervel rejects a data class when two properties use the same input path or output key instead of silently overwriting a value.

<a name="type-conversion"></a>
## Type Conversion

`from` casts supported scalar values, backed enums, dates, nested data objects, and typed iterables to their declared PHP types. Values that already have the declared type are kept as-is. Scalars, including typed iterable items, follow PHP's own weak typing: `'42'` becomes `42`, while a value PHP cannot convert, such as `'abc'` for an `int` or an array for a `string`, fails with PHP's `TypeError`. The strings `'true'` and `'false'` become booleans, and a scalar becomes a one-item array.

```php
class ProductData extends Data
{
    public function __construct(
        public int $stock,
        public float $price,
        public bool $active,
    ) {
    }
}

$product = ProductData::from([
    'stock' => '42',
    'price' => '19.95',
    'active' => 'true',
]);
```

A value that a union property already accepts is kept: a string given to `string|SongData` stays a string, and an array given to `array|Collection` stays an array. Declare `Collection` alone when the property should always hold a collection; an array given to it becomes one. Explicit casts still run first.

When a union declares several containers, the value's own container selects the declared type, and that type's item declaration still applies:

```php
use Hypervel\Support\Collection;

class PlaylistData extends Data
{
    /**
     * @param array<int, int>|Collection<int, SongData> $songs
     */
    public function __construct(
        public array|Collection $songs,
    ) {
    }
}

PlaylistData::from(['songs' => ['1', '2']])->songs;

// [1, 2]

PlaylistData::from(['songs' => collect([['title' => 'Never Gonna Give You Up']])])->songs;

// A Collection of SongData objects
```

Validation uses the rules of the selected type, so a string given to `string|SongData` is validated as a string. Hypervel does not guess between union types. With several declared data classes, or with several declared containers when more than one of them accepts the value or no declared type does, supply an existing compatible value, define an explicit cast, or return the complete data object from a typed named factory.

<a name="date-and-time-values"></a>
### Date and Time Values

Date interfaces use Hypervel's configured Date factory. If a property declares a concrete date class, Hypervel creates an instance of that exact class.

Input is parsed using the `data.date_format` configuration option, which accepts one format or an ordered list of formats. The `data.date_timezone` option converts parsed and transformed dates to the configured timezone. To use a different source timezone for one property, pass `timeZone` to `DateTimeInterfaceCast`. Its `setTimeZone` argument may be used to override the configured target timezone for that property.

Dates are transformed using the configured output format unless a property transformer overrides it:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeInterface;
use Hypervel\Data\Data;

class EventData extends Data
{
    public function __construct(
        public string $title,
        public DateTimeInterface $startsAt,
    ) {
    }
}
```

```php
$event = EventData::from([
    'title' => 'Conference',
    'startsAt' => '2026-04-30 09:00:00',
]);
```

For one property, select a different parser with `WithCast`:

```php
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;

class EventData extends Data
{
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public DateTimeInterface $startsAt,
    ) {
    }
}
```

<a name="nested-data-objects"></a>
### Nested Data Objects

Nested data objects are created recursively:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Hypervel\Data\Data;

class AddressData extends Data
{
    public function __construct(
        public string $street,
        public string $city,
        public string $postalCode,
    ) {
    }
}

class UserData extends Data
{
    public function __construct(
        public string $name,
        public ?AddressData $address,
    ) {
    }
}
```

```php
$user = UserData::from([
    'name' => 'Taylor Otwell',
    'address' => [
        'street' => '123 Main Street',
        'city' => 'Chicago',
        'postalCode' => '60601',
    ],
]);

$user->address->street;

// 123 Main Street
```

This works at any nesting depth. Existing `AddressData` instances pass through unchanged.

For a typed collection, use `DataCollectionOf` or a supported PHPDoc item annotation:

```php
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\DataCollection;

class TeamData extends Data
{
    public function __construct(
        #[DataCollectionOf(UserData::class)]
        public DataCollection $members,
    ) {
    }
}
```

The same typed item conversion works for arrays, ordinary collections, lazy collections, and supported paginator types. A paginator property must receive a Hypervel paginator or paginated data collection, since an array has no pagination details to keep. `DataCollectionOf` is preferred for generated classes because it declares the item type explicitly.

<a name="backed-enums"></a>
### Backed Enums

Backed enums are resolved from their backing values:

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

namespace App\Data;

use App\Enums\OrderStatus;
use Hypervel\Data\Data;

class OrderData extends Data
{
    public function __construct(
        public string $number,
        public OrderStatus $status,
    ) {
    }
}
```

```php
$order = OrderData::from([
    'number' => 'ORD-1000',
    'status' => 'paid',
]);

$order->status === OrderStatus::Paid;

// true
```

Integer-backed enums accept integral numeric values, including decimal and exponent strings such as `"1.0"` and `"1e0"`. Fractional values are rejected instead of being truncated to an enum case.

<a name="casts-and-transformers"></a>
## Casts and Transformers

Casts convert input into PHP values. Transformers convert PHP values into output. Attach them to a property with `WithCast`, `WithTransformer`, or `WithCastAndTransformer`:

```php
use Hypervel\Data\Attributes\WithCastAndTransformer;

class InvoiceData extends Data
{
    public function __construct(
        #[WithCastAndTransformer(MoneyCast::class)]
        public Money $total,
    ) {
    }
}
```

A cast implements `Hypervel\Data\Casts\Cast`; a transformer implements `Hypervel\Data\Transformers\Transformer`. Return `Uncastable::create()` from a cast when the next applicable candidate should be tried. Returning `null` means the cast produced a real null value.

A cast on a nested data object or data collection property receives the input before Hypervel builds any nested objects, so it may build them itself. Without validation, the cast receives the value exactly as it was given. When the object is validated, the value is first prepared and checked against the rules of its declared types, as it would be without the cast: models, objects, and collections become the arrays they would be built from, and the cast receives the validated arrays. A value that only the cast or a named method could interpret, such as a string for a nested data object, therefore fails validation. When every cast returns `Uncastable`, Hypervel builds the nested objects from the validated input. Named methods and constructors beneath the cast run only then, and a named method still receives the value it matched.

A cast's `cast` method receives the property, its input value, the object's declared property values keyed by property name, and the `CreationContext`. The context holds the options for the whole creation, so its `dataClass` is the class the creation started with. Undeclared input, contextual constructor values, and computed properties are not included. Properties that come earlier in the class have already been cast. Later properties contain their supplied input, or the default, `Optional`, or `null` value the object will receive when their input is absent. A value the constructor will assign to a property outside the constructor is not known yet:

```php
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

class MoneyCast implements Cast
{
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): Money
    {
        return new Money($value, $properties['currency']);
    }
}
```

Use `Castable` when a value class owns its input conversion, `IterableItemCast` when a cast also applies to typed iterable items, or `factory()->withCast()` for a single creation. Application-wide replacement casts and transformers belong in `config/data.php`; built-in date, enum, iterable, and `Arrayable` handling does not need to be configured. A cast registered for a type in a factory or the configuration also casts the items of iterables declared with that item type when it implements `IterableItemCast`.

Custom normalizers convert a source value into input before Hypervel reads its properties. Declare normalizers for a data class with `normalizers()` or add them to a factory with `withNormalizers()`. Prefer a typed named factory when only one source type needs special handling.

By default, a form request is read like any other request, and the data object validates the input with its own rules. To create data objects only from the input a form request has validated, add the optional `FormRequestNormalizer` to the `normalizers` option in `config/data.php`, or to a single class's `normalizers()`:

```php
'normalizers' => [
    Hypervel\Data\Normalizers\FormRequestNormalizer::class,
],
```

During a single creation or transformation, Hypervel may reuse the same cast, transformer, or normalizer instance for every matching value. Do not store per-value state on the extension object itself.

<a name="validation"></a>
## Validation

By default, `Data`, `Dto`, and `Resource` validate input when they are created from a request. Arrays, models, JSON, and other sources are not validated under the default `OnlyRequests` strategy. `Data` and `Dto` also provide a `validateAndCreate` method for explicitly validating an array-like payload:

```php
$user = UserData::validateAndCreate($payload);
```

Use `validate` when you only need the validated payload, or `getValidationRules` to inspect the generated rules:

```php
$validated = UserData::validate($payload);
$rules = UserData::getValidationRules($payload);
```

Hypervel infers presence, nullable, scalar, enum, date, nested data, and typed collection rules from your PHP declarations. These rules cover the entire nested object, including items within typed collections. A required collection of data objects must be present but may be empty. Uniform collections use wildcard rules, while collections with different item shapes or rules use exact indexed rules.

To adjust the rules of every data property, add your own rule inferrers to the `data.rule_inferrers` configuration option. An inferrer implements `Hypervel\Data\RuleInferrers\RuleInferrer`. After Hypervel's own inference, it receives the property, its rules, and a `ValidationContext`:

```php
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\StringType;
use Hypervel\Data\RuleInferrers\RuleInferrer;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Validation\PropertyRules;
use Hypervel\Data\Support\Validation\ValidationContext;

class MaxStringLengthRuleInferrer implements RuleInferrer
{
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($rules->hasType(StringType::class) && ! $rules->hasType(Max::class)) {
            $rules->add(new Max(255));
        }

        return $rules;
    }
}
```

Validation attributes take precedence over inferred rules of the same type. Inferrers are resolved from the container for each validation, so they may depend on scoped services.

The validator receives the complete input, so rules may refer to fields that are not data properties, such as the `password_confirmation` field read by `Confirmed` or a field used by `required_if`.

After validation, Hypervel creates the object from the validated values. Properties marked with `#[WithoutValidation]` are preserved, as are existing nested data objects. Other input is discarded. By default, this includes unvalidated keys nested inside an array; calling `Validator::includeUnvalidatedArrayKeys()` during your application's boot retains those nested keys.

<a name="validation-attributes"></a>
### Validation Attributes

Validation attributes mirror Hypervel's validation rules:

```php
use Hypervel\Data\Attributes\Validation\Email;
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Required;

class UserData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public string $name,
        #[Required, Email]
        public string $email,
    ) {
    }
}
```

Database-aware `Exists` and `Unique` attributes support the familiar fluent constraints. Use a `FieldReference` to refer to another input field rather than interpolating its name into a string.

Attribute arguments may also reference values that are only known during validation. `RouteParameterReference` reads a route parameter, `AuthenticatedUserReference` reads the authenticated user, and `ContainerReference` resolves a service from the container. Each reference accepts an optional property to read from the resolved value:

```php
use Hypervel\Data\Attributes\Validation\Max;
use Hypervel\Data\Attributes\Validation\Unique;
use Hypervel\Data\Support\Validation\References\AuthenticatedUserReference;
use Hypervel\Data\Support\Validation\References\ContainerReference;
use Hypervel\Data\Support\Validation\References\RouteParameterReference;

class SongData extends Data
{
    public function __construct(
        #[Max(new ContainerReference(SongSettings::class, 'maxTitleLength'))]
        public string $title,
        #[Unique('songs', ignore: new RouteParameterReference('song'))]
        public string $slug,
        #[Unique('users', 'email', ignore: new AuthenticatedUserReference(guard: 'api'))]
        public string $contactEmail,
    ) {
    }
}
```

A missing route parameter throws an exception unless the reference is created with `nullable: true`. A guest resolves to `null`. A container dependency that cannot be resolved throws an exception instead of producing a `null` rule value; pass `parameters` when the dependency needs constructor arguments.

<a name="manual-rules-and-hooks"></a>
### Manual Rules and Hooks

Define `rules`, `messages`, and `attributes` on the data class for rules that cannot be inferred:

```php
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Validation\Validator;

public static function rules(ValidationContext $context): array
{
    return [
        'email' => ['required', 'email:rfc'],
    ];
}
```

The `ValidationContext` provides the current object's input as `payload`, the complete input as `fullPayload`, and the object's input `path`. A class rule replaces inferred rules for that property. Add `#[MergeValidationRules]` to merge instead. Property keys use PHP property names; Hypervel translates them to the mapped input paths.

Use `withValidator(Validator $validator)` and `after(): array` like a FormRequest. Authorization, messages, translated attribute names, error bags, redirects, stop-on-first-failure, Precognition, and `#[FailOnUnknownFields]` use the corresponding Hypervel request-validation behavior. A declared class method overrides the matching Foundation attribute when both are present.

<a name="creation-factories"></a>
### Creation Factories

The `factory` method returns a fluent factory for creating data objects:

```php
$user = UserData::factory()
    ->alwaysValidate()
    ->prepareData(fn (array $data): array => [
        ...$data,
        'source' => 'import',
    ])
    ->withValidator(fn (Validator $validator) => $validator->after($check))
    ->from($payload);
```

Within one operation, you may reuse a factory to avoid repeating creation setup for every payload:

```php
$factory = UserData::factory();

$users = array_map(
    fn (array $payload): UserData => $factory->from($payload),
    $payloads,
);
```

Use `collect()` instead when the payloads form one collection. In addition to preserving supported collection shapes and keys, `collect()` allows collection validation rules and hooks to inspect the complete payload.

Factories may change the validation strategy, enable or disable name mapping and named factories, ignore selected named methods, and add casts or normalizers. They also provide the following hooks, which run in this order:

1. `prepareData`
2. `beforeValidation`
3. `beforeRules`
4. `afterRules`
5. `withValidator`
6. `afterValidation`
7. `beforeCreation`
8. `afterCreation`

The `prepareData`, `beforeCreation`, and `afterCreation` hooks run even when validation is skipped. The other hooks run while generating rules or validating, as appropriate. The `beforeValidation` hook receives the complete input and may add fields for the rules to read, while `afterValidation` receives the validated payload. Call `alwaysValidate()` when validation hooks should also apply to an array, model, JSON value, or another non-request source.

The `beforeCreation` hook receives the cast values that will be passed to the constructor or assigned after it. Properties declared outside the constructor whose input is missing are not included, so the hook may supply them; otherwise any value the constructor assigns is kept.

Each call to `factory()` returns a new factory. Keep a reused factory scoped to the current operation instead of storing it across requests.

A cast or named method that creates another data object may pass its `CreationContext` to `factory()` so the new object uses the same options:

```php
return AddressData::factory($context)->from($value);
```

The validation strategy, name mapping, named-method settings, casts, and normalizers are copied. Hooks are not, because they belong to the original creation.

<a name="transformation"></a>
## Transformation

`Data` and `Resource` transform their current property values. There is no serialized result cache, so later public-property assignments are visible immediately:

```php
$product = ProductData::from($payload);
$product->productName = 'Table';

$array = $product->toArray();
$json = $product->toJson();
```

`toArray()` recursively transforms nested transformable data, typed iterable items, dates, enums, and `Arrayable` values. The `all()` method returns visible property values without transforming nested values. For more control over a single transformation, pass a `TransformationContext` or `TransformationContextFactory` to `transform()`.

To guard against infinite recursion, such as when including a recursive relationship, set the `data.max_transformation_depth` configuration option. Reaching that depth throws an exception. Set `data.throw_when_max_transformation_depth_reached` to `false` to transform deeper values into an empty array instead, or pass `throw: false` to a factory's `maxDepth()` method for a single transformation. Values stored by Eloquent casts always throw instead of being truncated.

`Dto` has no transformation API. Use public properties directly, or choose `Data` or `Resource` when output mapping, `Optional` omission, lazy values, or built-in transformation is required.

<a name="lazy-properties"></a>
### Lazy Properties

A `Lazy` property is omitted until it is included:

```php
use Hypervel\Data\Lazy;

class UserData extends Data
{
    public function __construct(
        public string $name,
        public Lazy|ProfileData $profile,
    ) {
    }
}

$user = new UserData(
    'Taylor Otwell',
    Lazy::create(fn () => ProfileData::from($profile)),
);

return $user->include('profile')->toArray();
```

`Lazy::when` and `Lazy::whenLoaded` add conditional and relation-aware values. `Lazy::closure` returns the closure itself for consumers that understand callback values. Add `#[AutoLazy]`, `#[AutoClosureLazy]`, or `#[AutoWhenLoadedLazy]` to let `from()` wrap supplied values automatically.

Automatic lazy values postpone creating their nested values until they are included, unless validation needs them first.

When you create a custom `AutoLazy` attribute, its `build()` method receives the original source that supplied the property. If a validation hook changes the property, the method receives the payload returned by that hook instead. `AutoWhenLoadedLazy` requires an Eloquent model and throws an exception when no model source is available.

<a name="partial-trees"></a>
### Partial Trees

Use `include`, `exclude`, `only`, and `except` to select nested output paths:

```php
return $user
    ->include('profile.avatar')
    ->only('name', 'profile.*')
    ->except('profile.internalNotes')
    ->toArray();
```

The ordinary methods apply to the next transformation. Their `Permanently` variants apply to every transformation of that object, and the `When` variants accept a boolean or closure condition. A terminal `*` selects the complete subtree.

A malformed path, such as one with an empty segment, always throws an exception. A name that matches no property selects nothing, while a nested path through a property the object does not have throws an exception. Set the `data.ignore_invalid_partials` configuration option to `true` to skip those nested paths instead.

Selections owned by nested objects and collection items are composed with selections from their parent. Temporary selections are consumed only when that object is actually reached; collection reads and iteration do not consume them.

<a name="hidden-computed-and-appended-values"></a>
### Hidden, Computed, and Appended Values

`#[Hidden]` omits a declared property from ordinary output. `#[Computed]` marks an output-only property whose value is set by the class; caller input for it is rejected. To ignore that input instead, set the `data.features.ignore_exception_when_trying_to_set_computed_property_value` configuration option to `true`. PHP 8.4 virtual properties are treated as output-only in the same way.

Return response-only values from `with()` or add them to one object with `additional()`:

```php
public function with(): array
{
    return ['links' => ['self' => route('users.show', $this->id)]];
}

return $user->additional(['meta' => ['version' => 1]]);
```

These values are included in HTTP resource responses but are not stored by Eloquent. When a data object is dumped, it displays the same values as `all()`, so hidden, excluded lazy, and `Optional` values are omitted.

<a name="collections"></a>
## Collections

Use `collect()` to create several objects while preserving supported source shapes and keys:

```php
$users = UserData::collect($rows);
$dataCollection = UserData::collect($rows, DataCollection::class);
$collection = UserData::collect($rows, Collection::class);
$array = UserData::collect($rows, 'array');
```

The `$into` argument accepts `null`, `'array'`, or a class name. When the target comes from configuration, narrow it to a `class-string` before passing it so static analysis can infer the return type. When `$into` is `null`, arrays remain arrays, ordinary collections remain collections, and lazy collections remain lazy unless validation needs to read their values. Hypervel paginators are cloned with their pagination details intact. A paginator target requires a Hypervel paginator source because arrays and collections have no pagination details to keep. Eloquent collections become base support collections because data objects are not Eloquent models.

Collecting `null` items into an `'array'` or collection target returns that target empty, which suits optional relations and missing input. Without a target, or into a paginator target, `null` is rejected.

`DataCollection`, `PaginatedDataCollection`, and `CursorPaginatedDataCollection` provide typed items, transformation, and response behavior. `DataCollection` also provides keyed access when its underlying collection supports it. Use `toCollection()` for map, filter, reduce, and other collection operations. Paginated data collections cannot be stored directly by Eloquent because their pagination details cannot be recreated from a JSON array. Store their items through a `DataCollection` instead.

When a source remains lazy, every traversal creates its items again, and calling `count()` also traverses the source. If you need the items more than once, collect them into an eager collection and reuse it:

```php
$eagerUsers = $dataCollection->toCollection()->collect();
```

When validation is enabled, Hypervel validates the complete collection at once so collection rules and hooks can work with the entire payload. When collecting Eloquent models, Hypervel loads any relations requested with `#[LoadRelation]` before creating the data objects.

<a name="http-resources"></a>
## HTTP Resources

Return `Data`, `Resource`, or their collection wrappers directly from a controller:

```php
return UserData::from($user);

return UserData::collect($users, DataCollection::class);
```

Responses use Hypervel's JSON resources and paginator support, including their links and pagination details. Use `wrap()` or `withoutWrapping()` on an object or collection. You may also define the default wrapper using the `data.wrap` configuration option.

Override static `jsonOptions()` or `withResponse(Request $request, JsonResponse $response)` for Laravel-style response customization. A custom data collection class may also override `withResponse()`, while its JSON options come from its data class. Responses use the `200` status code for every request method, so set a `201` status for a newly created object yourself:

```php
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;

public function withResponse(Request $request, JsonResponse $response): void
{
    if ($request->isMethod('POST')) {
        $response->setStatusCode(201);
    }
}
```

Query-string `include`, `exclude`, `only`, and `except` selections are disabled unless the data class allows them through `allowedRequestIncludes()`, `allowedRequestExcludes()`, `allowedRequestOnly()`, or `allowedRequestExcept()`.

<a name="form-request-casting"></a>
## Form Request Casting

Declare a `Data`, `Dto`, or `Resource` class directly in a form request's protected `casts` method. The request creates the object after validation and returns it from `validated()` and `safe()`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\AddressData;
use App\Data\ContactData;
use App\Data\ProfileDto;
use App\Data\UserResource;
use Hypervel\Data\Http\Casts\AsDataCollection;
use Hypervel\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    protected function casts(): array
    {
        return [
            'address' => AddressData::class,
            'profile' => ProfileDto::class,
            'resource' => UserResource::class,
            'contacts' => AsDataCollection::of(ContactData::class),
        ];
    }
}
```

The object is built through its normal `from()` pipeline. A present `null` remains `null`, and a missing input is not added to the validated result. Direct `Data`, `Dto`, and `Resource` request casts do not accept cast arguments; Eloquent-only options such as `default` and `encrypted` do not apply here.

A [lightweight data object](#lightweight-data-objects) may also be declared directly when the form request owns validation and you only need typed construction and array or JSON output.

Use `AsDataCollection::of()` when the input contains several objects. It returns a `DataCollection` by default and accepts the same explicit targets as `collect()`, including `'array'` and `Hypervel\Support\Collection::class`:

```php
protected function casts(): array
{
    return [
        'contacts' => AsDataCollection::of(ContactData::class, 'array'),
    ];
}
```

For more information on request input casting, see the [validation documentation](/docs/{{version}}/validation#casting-form-request-data).

<a name="eloquent-casting"></a>
## Eloquent Casting

`Data`, `Resource`, and `DataCollection` implement Eloquent's `Castable` contract. Use the data class directly in a model's cast declaration:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\MemberData;
use App\Data\UserProfileData;
use Hypervel\Data\DataCollection;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    protected function casts(): array
    {
        return [
            'profile' => UserProfileData::class,
            'members' => DataCollection::class . ':' . MemberData::class,
        ];
    }
}
```

Eloquent stores all values needed to recreate the data object using its PHP property names. Hidden properties are stored, while computed, virtual, appended, and response-only values are omitted. Partial selections do not change the stored value and are not consumed. Output transformers still run, so a one-way transformer needs a matching input cast or `WithCastAndTransformer` to recreate the original value.

Conditional and relation lazy values must already be included when the model is saved, and saving never loads a relation. Closure and Inertia lazy values cannot be stored because they do not resolve to ordinary data values.

Both casts support `encrypted` and `default` arguments. Abstract data classes use an enforced alias map unless they select a concrete subtype through `PropertyMorphableData::morph()`:

```php
use Hypervel\Data\Support\DataConfig;

public function boot(DataConfig $data): void
{
    $data->enforceMorphMap([
        'card' => CardPaymentData::class,
        'bank' => BankPaymentData::class,
    ]);
}
```

Morph maps are boot-time configuration. Unknown aliases and payload-provided class names are rejected.

For more information on Eloquent casts, see the [Eloquent mutators and casts documentation](/docs/{{version}}/eloquent-mutators#data-object-casting).

<a name="contextual-constructor-values"></a>
## Contextual Constructor Values

Hypervel contextual attributes may supply constructor values from framework services without Data-specific injection aliases:

```php
use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Container\Attributes\RouteParameter;

class UpdatePostData extends Data
{
    public function __construct(
        public string $title,
        #[CurrentUser(property: 'id')]
        public int $userId,
        #[RouteParameter('post', 'id')]
        public int $postId,
    ) {
    }
}
```

Contextual values are resolved only after validation succeeds. They always take precedence over input and creation hooks, including when the resolved value is `null`. A promoted property's value is converted to its declared type like other input, without validation, so a `'123'` route parameter becomes an `int` and an array becomes a nested data object. Input supplied for a promoted contextual property is allowed by strict unknown-field validation but is ignored. A non-promoted contextual parameter with its own name is passed only to the constructor. Use a named factory or creation hook without the contextual attribute when input should take precedence.

`CurrentUser` and `RouteParameter` accept an optional `property` path and use `data_get()` semantics. Accessors and Eloquent relations may run while traversing that path. `RequestAttribute` selects an exact request attribute key. The `Config`, `Context`, and `Give` attributes are also supported, as are custom contextual attributes.

<a name="inertia"></a>
## Inertia

When `hypervel/inertia` is installed, Data lazy values can produce Inertia props:

```php
public function __construct(
    public Lazy|ProfileData $profile,
    public Lazy|ActivityData $activity,
) {
}

$data = new DashboardData(
    Lazy::inertia(fn () => ProfileData::from($profile)),
    Lazy::inertiaDeferred(fn () => ActivityData::from($activity), group: 'activity'),
);
```

`#[AutoInertiaLazy]` and `#[AutoInertiaDeferred]` provide automatic variants. Existing `DeferProp` instances retain their merge, caching, grouping, and rescue settings.

<a name="saloon"></a>
## Saloon

Hypervel Saloon may return a Data object directly from `createDtoFromResponse`:

```php
use Hypervel\Data\Data;
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Traits\Responses\HasResponse;

final class GitHubUserData extends Data implements WithResponse
{
    use HasResponse;

    public function __construct(
        public int $id,
        public string $login,
    ) {
    }
}

public function createDtoFromResponse(Response $response): GitHubUserData
{
    return GitHubUserData::from($response->json());
}
```

Saloon attaches the response to the data object through its existing `WithResponse` contract.

<a name="generating-data-classes"></a>
## Generating Data Classes

Generate a class with the `make:data` command:

```shell
php artisan make:data User
```

By default, the class is placed in the `App\Data` namespace and suffixed with `Data`, so this command creates `App\Data\UserData`. A name that already ends with the suffix is left unchanged. You may change these defaults using the `data.commands.make.namespace` and `data.commands.make.suffix` configuration options. Set the suffix to an empty string to disable it.

For a single class, pass `--suffix` to use a different suffix or `--target-namespace` to choose the complete namespace:

```shell
php artisan make:data Post --suffix=Dto --target-namespace="App\DataTransferObjects"
```

Like Hypervel's other generators, the command honors an application stub override and supports `--force`.

<a name="worker-lifetime"></a>
## Worker Lifetime

Hypervel analyzes each data class when it is first used and keeps that description for the worker lifetime. The cached description never contains request data or values from a data object. There is no metadata cache command to run when deploying your application.

Register `Lazy`, `DataCollection`, `PaginatedDataCollection`, and `CursorPaginatedDataCollection` macros during provider boot. These macros remain registered for the worker lifetime, so they must not contain request-specific callbacks or values. Configure morph aliases during boot for the same reason.

When dumped, a transformable data object displays the same values as `all()`. Data collections display their values under an `items` key. Internal package state is not included. By default, this dump format is only used in the `local` and `testing` environments. Set the `data.var_dumper_caster_mode` configuration option to `enabled` to use it in every environment, or `disabled` to dump the complete object.

Data objects do not implement `ArrayAccess`. Read public properties or call `toArray()`. Data collections support enumeration and provide keyed access when their underlying collection supports it.

<a name="credits"></a>
## Credits

Hypervel Data began as a port of [Spatie Laravel Data](https://github.com/spatie/laravel-data) and has been adapted for Hypervel's framework architecture and coroutine runtime.
