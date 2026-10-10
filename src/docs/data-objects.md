# Data Objects

- [Introduction](#introduction)
- [Getting Started](#getting-started)
    - [Generating Data Classes](#generating-data-classes)
    - [Choosing a Base Class](#choosing-a-base-class)
- [Creating Data Objects](#creating-data-objects)
    - [Creating Instances](#creating-instances)
    - [Creating From Requests](#creating-from-requests)
    - [Creating From Models](#creating-from-models)
    - [Associating a Data Class](#associating-a-data-class)
    - [Defaults, Null, and Optional Values](#defaults-null-and-optional-values)
    - [Empty Representations](#empty-representations)
    - [Constructor Properties](#constructor-properties)
    - [Named Factories](#named-factories)
    - [Preparing Input](#preparing-input)
    - [Property Name Conversion](#property-name-conversion)
    - [Creation Factories](#creation-factories)
- [Type Conversion](#type-conversion)
    - [Date and Time Values](#date-and-time-values)
    - [Nested Data Objects](#nested-data-objects)
    - [Abstract Data Objects](#abstract-data-objects)
    - [Backed Enums](#backed-enums)
- [Casts and Transformers](#casts-and-transformers)
    - [Custom Casts](#custom-casts)
    - [Custom Transformers](#custom-transformers)
    - [Global Casts and Transformers](#global-casts-and-transformers)
- [Normalizers](#normalizers)
- [Validation](#validation)
    - [Inferred Rules](#inferred-rules)
    - [Validation Attributes](#validation-attributes)
    - [Manual Rules and Hooks](#manual-rules-and-hooks)
    - [Authorizing Requests](#authorizing-requests)
    - [Customizing the Validator](#customizing-the-validator)
- [Transformation](#transformation)
    - [Lazy Properties](#lazy-properties)
    - [Including and Excluding Properties](#including-and-excluding-properties)
    - [Hidden, Computed, and Appended Values](#hidden-computed-and-appended-values)
- [Collections](#collections)
- [HTTP Resources](#http-resources)
    - [Wrapping](#wrapping)
    - [Paginated Responses](#paginated-responses)
    - [Customizing Responses](#customizing-responses)
    - [Selecting Properties From Requests](#selecting-properties-from-requests)
- [Form Request Casting](#form-request-casting)
- [Eloquent Casting](#eloquent-casting)
    - [Defaults and Encryption](#eloquent-defaults-and-encryption)
    - [Abstract Classes](#eloquent-abstract-classes)
- [Contextual Constructor Values](#contextual-constructor-values)
- [Inertia](#inertia)
- [Saloon](#saloon)
- [Lightweight Data Objects](#lightweight-data-objects)
- [Worker Lifetime](#worker-lifetime)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel Data provides an expressive way to turn arrays, requests, Eloquent models, and other input into typed PHP objects. These objects may validate incoming data, transform values for output, be collected, returned from routes and controllers, and stored with Eloquent.

If you have used Spatie Laravel Data, the package's classes and methods should feel familiar. Hypervel Data provides this familiar API while remaining suitable for Hypervel's long-running workers.

<a name="getting-started"></a>
## Getting Started

To define a data object, extend `Data` and declare its public properties:

```php
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

You may create the object directly, or use `from` to convert input values to the declared types:

```php
$user = UserData::from([
    'name' => 'Taylor Otwell',
    'age' => '39',
    'email' => 'taylor@example.com',
]);

$user->age; // 39
$user->toArray();
```

Data objects can also [validate incoming requests](#creating-from-requests), [represent Eloquent models](#creating-from-models), and be [returned directly from controllers](#http-resources).

<a name="generating-data-classes"></a>
### Generating Data Classes

Generate a class with the `make:data` command:

```shell
php artisan make:data User
```

By default, this creates `App\Data\UserData`. A name that already ends with `Data` is left unchanged. You may change these defaults using the `data.commands.make.namespace` and `data.commands.make.suffix` configuration options. Set the suffix to an empty string to disable it.

For a single class, pass `--suffix` or `--target-namespace`:

```shell
php artisan make:data Post --suffix=Dto --target-namespace="App\DataTransferObjects"
```

Like Hypervel's other generators, the command honors an application stub override and supports `--force`. To customize the package's configuration, publish its configuration file:

```shell
php artisan vendor:publish --tag=data-config
```

<a name="choosing-a-base-class"></a>
### Choosing a Base Class

The package provides three base classes:

- `Data` supports creation, validation, transformation, HTTP responses, collections, and Eloquent casting.
- `Dto` supports creation and validation without transformation or response behavior. It is a good choice for commands, service boundaries, and domain input.
- `Resource` supports creation, transformation, HTTP responses, collections, and Eloquent casting without the public validation methods.

Choose the base class that provides the behavior your object needs. Since `Dto` does not transform values, nested or collected DTOs remain objects when a surrounding `Data` object is transformed. Use `Data` or `Resource` when nested values should also be transformed.

For trusted internal values that only need typed construction and array or JSON output, consider a [lightweight data object](#lightweight-data-objects).

<a name="creating-data-objects"></a>
## Creating Data Objects

Data objects are ordinary PHP objects, so you may construct one directly when you already have values of the correct types:

```php
$user = new UserData('Taylor Otwell', 39, 'taylor@example.com');
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

<a name="creating-from-requests"></a>
### Creating From Requests

You may type-hint a data class in a controller method. Hypervel creates it from the current request and validates the input before calling your method:

```php
use App\Data\UserData;
use App\Models\User;
use Hypervel\Http\RedirectResponse;

public function store(UserData $data): RedirectResponse
{
    User::create($data->toArray());

    return redirect()->route('users.index');
}
```

The property's type supplies its basic validation rules. Add [validation attributes or manual rules](#validation) for requirements such as a valid email address. If validation fails, Hypervel returns the usual validation response instead of calling the controller.

You may also pass a request explicitly:

```php
$data = UserData::from($request);
```

Under the default validation strategy, passing `$request->all()` instead passes an array and does not trigger validation. Use `validateAndCreate()` when an array needs validation.

<a name="creating-from-models"></a>
### Creating From Models

Pass an Eloquent model to `from` to read its attributes, including model casts and accessors:

```php
$data = UserData::from(User::findOrFail($id));
```

By default, camel-case properties read the matching snake-case model attributes, so `createdAt` reads `created_at` without a mapper.

Loaded relationships can become nested data objects or typed collections. For example, an album with its songs may be represented as follows:

```php
use Hypervel\Support\Collection;

class SongData extends Data
{
    public function __construct(public string $title)
    {
    }
}

class AlbumData extends Data
{
    /**
     * @param Collection<int, SongData> $songs
     */
    public function __construct(
        public string $title,
        public Collection $songs,
    ) {
    }
}

$album = AlbumData::from(Album::with('songs')->findOrFail($id));
```

Unloaded relationships are treated as missing. Eager-load the relationships you need, or mark a property with `#[LoadRelation]` to load it automatically:

```php
use Hypervel\Data\Attributes\LoadRelation;

#[LoadRelation]
public ArtistData $artist;
```

When collecting models, Hypervel loads the requested relationships together. For relationships that should appear only when already loaded, use [relation-aware lazy properties](#lazy-properties).

Avoid automatic loading on both sides of a circular relationship, such as an artist's songs and each song's artist, since creation would keep loading and constructing the same relationships.

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

When a FormRequest uses `WithData`, `getData()` runs the associated data class's authorization and validation rules. It does not reuse the FormRequest's rules. To construct from the FormRequest's validated result instead, pass `$request->validated()` directly to `UserData::from()` or enable the [`FormRequestNormalizer`](#normalizers).

<a name="defaults-null-and-optional-values"></a>
### Defaults, Null, and Optional Values

For constructor properties, missing input uses the declared default, then `Optional`, then `null` if the type allows it. Any other missing property fails validation or construction.

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

`Optional` values are omitted from transformed output. This is useful for a PATCH request, where an absent value means the existing value should remain unchanged:

```php
PatchUserData::from(['phone' => null])->toArray();

// ['phone' => null, 'locale' => 'en']
```

Call `factory()->withoutOptionalValues()` when missing nullable properties should receive `null` instead of `Optional`. A missing `string|null|Optional` property then receives `null`, while a missing `string|Optional` property keeps `Optional` because it cannot hold `null`.

A property declared outside the constructor keeps a constructor-assigned value when its input is missing or `Optional`. This allows defaults that need to be calculated:

```php
use Hypervel\Support\CarbonImmutable;

class PublishedData extends Data
{
    public CarbonImmutable|Optional $publishedAt;

    public function __construct()
    {
        $this->publishedAt = CarbonImmutable::now();
    }
}
```

Supplied input, including `null`, replaces that value. Validation infers rules from the declaration alone, so when requests may omit such a property, give it a nullable or `Optional` type or add `#[Sometimes]`.

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

You may pass replacement values as the first argument, change the value used for otherwise empty properties with `replaceNullValuesWith`, or filter the result using `only` and `except`. Replacement values use PHP property names and apply only to properties without a declared default. The filters use output names:

```php
UserData::empty(
    ['name' => 'Unknown'],
    except: ['active'],
);
```

<a name="constructor-properties"></a>
### Constructor Properties

Constructor-promoted `readonly` properties are supported, including properties promoted by a parent constructor that your constructor calls:

```php
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

Public properties declared outside the constructor are assigned after construction. They cannot be `readonly`, unless the class sets the value itself as a `#[Computed]` property.

A plain constructor parameter without a matching public property receives input with the same name, unchanged. Name mapping, casts, and inferred rules do not apply to it, but explicit `rules()` and creation hooks do. Eloquent models do not supply these values:

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

SearchData::from(['query' => '  hypervel ']);
```

Input never sets a `protected` or `private` promoted property. It keeps its default, and creation fails when it has none, unless a `beforeCreation` hook or named factory supplies it.

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

Named methods may receive dependencies from the service container as well as a `CreationContext`. A named method must return the requested data object. Hypervel uses it directly without validating, casting, or running creation hooks for it again.

> [!WARNING]
> A named method that accepts a request is responsible for validating it. The data class's authorization still runs first.

To reshape input while keeping ordinary validation and creation, use a [normalizer](#normalizers) or [`prepareForPipeline()`](#preparing-input). Beneath a property with an explicit cast, a named method runs only if the cast declines the value. When the object is validated, validation checks the value against the declared rules first.

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

class RecordedSongData extends Data
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

$song = RecordedSongData::from([
    'title' => 'Never Gonna Give You Up',
    'year' => '1987',
    'producer' => 'Stock Aitken Waterman',
]);
```

The method receives each payload separately. When a factory has `prepareData` hooks, they run first, and the method receives their merged result once. An Eloquent model is passed as an array of its declared property values. For a property-morphable class, the method of the selected class is called. A named factory that returns the finished object skips this method.

<a name="property-name-conversion"></a>
### Property Name Conversion

Input and output names are unchanged by default. Use mapping attributes when an input or output key differs from the PHP property name:

```php
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

```php
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class ProductData extends Data
{
    public function __construct(public string $productName)
    {
    }
}

ProductData::from(['product_name' => 'Desk'])->toArray();

// ['product_name' => 'Desk']
```

Set application-wide defaults in `config/data.php`:

```php
'name_mapping_strategy' => [
    'input' => SnakeCaseMapper::class,
    'output' => SnakeCaseMapper::class,
],
```

To read a nested input value, use a dot-separated path, such as `#[MapInputName('artist.name')]` or `#[MapInputName('artists.0.name')]`.

Validated input must use the mapped path, which is where its rules apply. Without validation, the PHP property name is read when the mapped input is missing. Hypervel rejects conflicting input paths or output keys instead of silently overwriting a value.

<a name="creation-factories"></a>
### Creation Factories

Use `factory()` to customize a single creation. For example, you may validate an import or disable named factories:

```php
$user = UserData::factory()->alwaysValidate()->from($payload);

$user = UserData::factory()->withoutMagicalCreation()->from($payload);
```

Other options include `withoutValidation()`, `onlyValidateRequests()`, `withoutPropertyNameMapping()`, `ignoreMagicalMethod('fromModel')`, and [`withoutOptionalValues()`](#defaults-null-and-optional-values). Use `withCast(Money::class, MoneyCast::class)` or `withNormalizers(CustomNormalizer::class)` to customize conversion for this creation.

Within one operation, you may reuse a factory to avoid repeating setup:

```php
$factory = UserData::factory();

$users = array_map(
    fn (array $payload): UserData => $factory->from($payload),
    $payloads,
);
```

Use `collect()` instead when the payloads form one collection. It preserves supported collection shapes and keys, and allows validation rules and hooks to inspect the complete payload.

#### Creation Hooks

Factories also let you prepare input and customize validation or construction:

```php
$user = UserData::factory()
    ->alwaysValidate()
    ->prepareData(fn (array $data): array => [
        ...$data,
        'name' => trim($data['name']),
    ])
    ->from($payload);
```

The available hooks run in the following order. Each receives the arguments shown and returns the replacement value, except `withValidator`, which customizes the validator directly:

| Hook | Arguments | Return value |
| --- | --- | --- |
| `prepareData` | Input array | Input array |
| `beforeValidation` | Complete input array | Input array |
| `beforeRules` | `DataProperty`, `ValidationPath`, value | Rules, or `null` for ordinary inference |
| `afterRules` | Rules, `DataProperty`, `ValidationPath`, value | Rules |
| `withValidator` | `Validator` | None |
| `afterValidation` | Validated input array | Input array |
| `beforeCreation` | Cast property values | Property values |
| `afterCreation` | Created data object | Data object |

The `prepareData`, `beforeCreation`, and `afterCreation` hooks run even when validation is skipped. The other hooks run while generating rules or validating, as appropriate. Call `alwaysValidate()` when validation hooks should also apply to non-request input.

`beforeValidation` receives the complete input and may add fields for rules to read; `afterValidation` receives the validated payload. The values these hooks return are treated as prepared input, so normalizers, `prepareForPipeline()`, and `prepareData` hooks do not run on them again. `beforeCreation` receives the cast values that will be passed to the constructor or assigned after it. Properties declared outside the constructor whose input is missing are not included, so the hook may supply them; otherwise any value the constructor assigns is kept.

Each call to `factory()` returns a new factory. Keep a reused factory scoped to the current operation instead of storing it across requests.

A cast or named method may pass its `CreationContext` to another class's factory:

```php
return AddressData::factory($context)->from($value);
```

The validation strategy, name mapping, named-method settings, casts, and normalizers are copied. Hooks are not, because they belong to the original creation.

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

Input is parsed using `data.date_format`, which defaults to `DATE_ATOM`. Set an ordered list to accept several formats; the first is also used for output:

```php
'date_format' => [DATE_ATOM, 'Y-m-d'],
```

The `data.date_timezone` option converts parsed and transformed dates to the configured timezone. To use a different source timezone for one property, pass `timeZone` to `DateTimeInterfaceCast`. Its `setTimeZone` argument overrides the configured target timezone for that property.

For example, the default `DATE_ATOM` format accepts a date with its timezone:

```php
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
    'startsAt' => '2026-04-30T09:00:00+00:00',
]);
```

Use `WithCast` and `WithTransformer` to choose a property's input and output formats:

```php
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Transformers\DateTimeInterfaceTransformer;

class EventData extends Data
{
    public function __construct(
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        #[WithTransformer(DateTimeInterfaceTransformer::class, format: 'd/m/Y')]
        public DateTimeInterface $startsAt,
    ) {
    }
}

EventData::from(['startsAt' => '2026-04-30'])->toArray();

// ['startsAt' => '30/04/2026']
```

<a name="nested-data-objects"></a>
### Nested Data Objects

Nested data objects are created recursively:

```php
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

The same typed item conversion works for arrays, ordinary collections, lazy collections, and supported paginator types. A paginator property must receive a Hypervel paginator or paginated data collection, since an array has no pagination details to keep. `DataCollectionOf` declares the item type in code rather than in a PHPDoc comment.

An item type may mix a data class with other types, such as `list<LabelData|string>`, when you construct the object yourself. Its items transform by value, so strings stay strings and data objects become arrays. Creating such an object from input with `from` is not supported: every item that is not already a data object is created as the data class, so a string item fails.

Without an item type, a data collection property can still hold a collection you created yourself, and its items transform by value. Since the item class is unknown, `from` cannot create that property from an array.

<a name="abstract-data-objects"></a>
### Abstract Data Objects

An abstract data class may choose a concrete subclass from its input. Implement `PropertyMorphableData` and mark the properties used to make that choice with `#[PropertyForMorph]`:

```php
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Contracts\PropertyMorphableData;

abstract class PaymentData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['type']) {
            'card' => CardPaymentData::class,
            'bank' => BankPaymentData::class,
            default => null,
        };
    }
}

class CardPaymentData extends PaymentData
{
    public function __construct(string $type, public string $token)
    {
        parent::__construct($type);
    }
}

class BankPaymentData extends PaymentData
{
    public function __construct(string $type, public string $account)
    {
        parent::__construct($type);
    }
}

$payment = PaymentData::from(['type' => 'card', 'token' => 'tok_123']);

// CardPaymentData
```

The `morph` method receives only the marked properties and must return a concrete subclass, or `null` when none matches. Selection happens before validation, so handle unexpected input values. Validation then uses the selected class's rules. This also works for nested properties and collections declared with the abstract item type.

<a name="backed-enums"></a>
### Backed Enums

Backed enums are resolved from their backing values:

```php
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
```

```php
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

Casts convert input into PHP values. Transformers convert PHP values into output. For example, a money value may be created from an integer amount and transformed back to that amount:

```php
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Attributes\WithTransformer;

class Money
{
    public function __construct(public int $amount, public string $currency)
    {
    }
}

class InvoiceData extends Data
{
    public function __construct(
        public string $currency,
        #[WithCast(MoneyCast::class), WithTransformer(MoneyTransformer::class)]
        public Money $total,
    ) {
    }
}
```

<a name="custom-casts"></a>
### Custom Casts

A cast implements `Cast`. Its `cast` method receives the property, its input value, the object's other property values, and the creation options:

```php
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

class MoneyCast implements Cast
{
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): Money
    {
        return $value instanceof Money
            ? $value
            : new Money($value, $properties['currency']);
    }
}
```

```php
$invoice = InvoiceData::from(['currency' => 'USD', 'total' => 1999]);
$invoice->total->amount; // 1999
```

Return `Hypervel\Data\Casts\Uncastable::create()` when another cast or the built-in conversion should be tried. Returning `null` means the cast produced a real null value.

Casts and transformers are not called for `null`, which stays `null`. Use a named factory or `prepareForPipeline()` to replace it.

The `$properties` argument uses PHP property names. Earlier properties have already been cast; later properties contain their input or missing-value defaults. It excludes undeclared input, contextual and computed values, and values the constructor has yet to assign. The `CreationContext` holds options for the whole creation, so its `dataClass` remains the class the creation started with.

Use `Hypervel\Data\Casts\Castable` when a value class owns its conversion. Its static `dataCastUsing(array $arguments): Cast` method returns the cast. Attach it with `#[WithCastable(EmailAddress::class)]`; attribute arguments are passed to `dataCastUsing` as an array.

For typed iterable items, implement `IterableItemCast::castIterableItem()` with the same parameters as `cast()`. A cast registered for an item type through configuration or `factory()->withCast()` then applies to every item of that type.

#### Casting Nested Data

A cast on a nested data object or collection receives input before Hypervel constructs its children. With validation enabled, models, objects, and collections are first prepared as arrays and validated against the declared types. The cast receives those validated arrays. Input that only the cast could interpret, such as a string for a nested data object, fails validation.

Without validation, the cast receives the original value. If every cast declines, ordinary nested creation continues; named factories and constructors run at that point.

<a name="custom-transformers"></a>
### Custom Transformers

A transformer implements `Transformer` and returns a value suitable for output:

```php
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\Transformer;

class MoneyTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): int
    {
        return $value->amount;
    }
}

$invoice->toArray();

// ['currency' => 'USD', 'total' => 1999]
```

You may implement both `Cast` and `Transformer` on one class and attach it with `#[WithCastAndTransformer(MoneyCastAndTransformer::class)]`.

<a name="global-casts-and-transformers"></a>
### Global Casts and Transformers

Register application-wide casts and transformers by type in `config/data.php`:

```php
'casts' => [
    Money::class => MoneyCast::class,
],

'transformers' => [
    Money::class => MoneyTransformer::class,
],
```

These entries are your own extensions; built-in date, enum, iterable, and `Arrayable` handling needs no configuration. Keys may name a concrete class, base class, or interface. Property attributes take precedence.

During a single creation or transformation, Hypervel may reuse the same extension instance for every matching value. Do not store per-value state on a cast, transformer, or normalizer.

<a name="normalizers"></a>
## Normalizers

Custom normalizers convert a source value into input before Hypervel reads its properties. Declare normalizers for a data class with `normalizers()` or add them to a factory with `withNormalizers()`. Prefer a typed named factory when only one source type needs special handling.

Implement `Normalizer` and return an array, or `null` when the normalizer does not handle the value:

```php
use Hypervel\Data\Normalizers\Normalizer;

class ContactNormalizer implements Normalizer
{
    public function normalize(mixed $value): ?array
    {
        if (! $value instanceof Contact) {
            return null;
        }

        return ['name' => $value->name, 'email' => $value->email];
    }
}
```

Register it on the data class:

```php
public static function normalizers(): array
{
    return [ContactNormalizer::class];
}
```

Class normalizers run first, followed by factory normalizers, global normalizers, and built-in conversion. The first matching normalizer wins. Named factories take precedence over normalization.

By default, a form request is read like any other request, and the data object validates the input with its own rules. To create data objects only from the input a form request has validated, add the optional `FormRequestNormalizer` to the `normalizers` option in `config/data.php`, or to a single class's `normalizers()`:

```php
'normalizers' => [
    Hypervel\Data\Normalizers\FormRequestNormalizer::class,
],
```

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

Validation runs before construction, not when you call `new` or change a public property. For per-operation control, use a [creation factory](#creation-factories). You may also change the default in `config/data.php`:

```php
use Hypervel\Data\Support\Creation\ValidationStrategy;

'validation_strategy' => ValidationStrategy::Always->value,
```

<a name="inferred-rules"></a>
### Inferred Rules

Hypervel infers presence, nullable, scalar, enum, date, nested data, and typed collection rules from your PHP declarations. These rules cover the entire nested object, including items within typed collections. A required collection of data objects must be present but may be empty. Uniform collections use wildcard rules, while collections with different item shapes or rules use exact indexed rules.

For example, `public int $age` requires an integer, while `public ?string $phone` accepts a string or `null`. Defaults and `Optional` allow input to be omitted. Use `getValidationRules($payload)` to inspect the rules for a particular input; nested rules depend on which values are present.

The validator receives the complete input, so rules may refer to fields that are not data properties, such as the `password_confirmation` field read by `Confirmed` or a field used by `required_if`.

After validation, Hypervel creates the object from the validated values. Properties marked with `#[WithoutValidation]` are preserved, as are existing nested data objects. Other input is discarded. By default, this includes unvalidated keys nested inside an array; calling `Validator::includeUnvalidatedArrayKeys()` during your application's boot retains those nested keys.

<a name="validation-attributes"></a>
### Validation Attributes

Validation attributes mirror Hypervel's validation rules:

```php
use Hypervel\Data\Attributes\Validation\Email;
use Hypervel\Data\Attributes\Validation\Max;

class UserData extends Data
{
    public function __construct(
        #[Max(100)]
        public string $name,
        #[Email]
        public string $email,
    ) {
    }
}
```

There is no need to add `Required` or `StringType` here; they are inferred from the property declarations. Attributes take precedence over inferred rules of the same type. You may also use `#[Rule('required|string')]` or `#[Rule(['required', 'string'])]` for ordinary rule syntax. See the [validation rule reference](/docs/{{version}}/validation#available-validation-rules) for rule behavior.

To skip validation for one property, add `#[WithoutValidation]`. Its input is still used during creation, so use this only when that value does not need validation.

Database-aware `Exists` and `Unique` attributes support the familiar fluent constraints. Within an attribute, use constraint classes such as `WhereConstraint`, `WhereInConstraint`, or `WhereNullConstraint` to add conditions:

```php
use Hypervel\Data\Attributes\Validation\Exists;
use Hypervel\Data\Support\Validation\Constraints\WhereConstraint;

#[Exists('users', 'id', where: new WhereConstraint('active', true))]
public int $userId;
```

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

For a reusable validation attribute, extend `CustomValidationAttribute` and return rules from `getRules()`:

```php
use Attribute;
use Hypervel\Data\Attributes\Validation\CustomValidationAttribute;
use Hypervel\Data\Support\Validation\ValidationPath;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Slug extends CustomValidationAttribute
{
    public function getRules(ValidationPath $path): array
    {
        return ['alpha_dash', 'max:100'];
    }
}
```

<a name="manual-rules-and-hooks"></a>
### Manual Rules and Hooks

Define `rules()` on the data class for rules that cannot be inferred:

```php
use Hypervel\Data\Support\Validation\ValidationContext;

public static function rules(ValidationContext $context): array
{
    return [
        'email' => ['required', 'email:rfc'],
    ];
}
```

The `ValidationContext` provides the current object's input as `payload`, the complete input as `fullPayload`, and the object's input `path`. A class rule replaces inferred rules for that property. Add `#[MergeValidationRules]` to the class to merge them instead. Property keys use PHP property names; Hypervel translates them to the mapped input paths.

Validation attributes, including `#[Rule]`, may reference another property by its PHP name; Hypervel converts it to the input name. Rule strings returned from `rules()` or rule hooks are passed to the validator as written, so use input names there, or return an attribute such as `new RequiredWith('lastName')`.

For references from a nested object to the root input, use `FieldReference`:

```php
use Hypervel\Data\Attributes\Validation\RequiredIf;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[RequiredIf(new FieldReference('requiresAddress', fromRoot: true), true)]
public ?string $street;
```

<a name="authorizing-requests"></a>
### Authorizing Requests

Define `authorize()` to decide whether a request may create the data object. Dependencies are resolved from the container:

```php
use Hypervel\Contracts\Auth\Access\Gate;

public static function authorize(Gate $gate): bool
{
    return $gate->allows('create', User::class);
}
```

Returning `false` throws an authorization exception. You may instead return a `Hypervel\Auth\Access\Response`. Authorization applies when the source includes a request; creating from an array does not authorize the current request.

<a name="customizing-the-validator"></a>
### Customizing the Validator

Define `messages()` and `attributes()` to customize errors and field labels:

```php
public static function messages(): array
{
    return ['email.required' => 'Please provide an email address.'];
}

public static function attributes(): array
{
    return ['email' => 'email address'];
}
```

Use `withValidator()` to customize the validator, or return callbacks from `after()` for checks that run after its rules. For example, a class with required integer `start` and `end` properties can compare them after validation:

```php
use Hypervel\Validation\Validator;

public static function after(): array
{
    return [
        function (Validator $validator): void {
            if ($validator->errors()->isEmpty()
                && $validator->getData()['start'] > $validator->getData()['end']
            ) {
                $validator->errors()->add('end', 'The end must follow the start.');
            }
        },
    ];
}
```

Use the Foundation HTTP attributes `#[ErrorBag]`, `#[RedirectTo]`, `#[RedirectToRoute]`, `#[StopOnFirstFailure]`, and `#[FailOnUnknownFields]` as with [form requests](/docs/{{version}}/validation#form-request-validation). Static `errorBag()`, `redirect()`, `redirectRoute()`, and `stopOnFirstFailure()` methods override their corresponding attributes. Precognition is also supported. `withValidator` and `after` run on the root data class, not separately on each nested object.

#### Custom Rule Inferrers

To adjust every property's inferred rules, add a class to `data.rule_inferrers`. It receives the property, its rules, and a `ValidationContext` after built-in inference:

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

Rules written in a `Rule` attribute count as their matching attribute types, so inferrers can inspect them by type. Inferrers are resolved from the container for each validation and may depend on scoped services.

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

```php
use Hypervel\Data\Support\Transformation\TransformationContextFactory;

$array = $product->transform(
    TransformationContextFactory::create()->withoutPropertyNameMapping(),
);
```

The factory also provides `withoutValueTransformation()`, `withWrapping()`, and `withTransformer(Money::class, MoneyTransformer::class)`.

To guard against infinite recursion, such as when including a recursive relationship, set the `data.max_transformation_depth` configuration option. Reaching that depth throws an exception. Set `data.throw_when_max_transformation_depth_reached` to `false` to transform deeper values into an empty array instead, or pass `throw: false` to a factory's `maxDepth()` method for a single transformation. Values stored by Eloquent casts always throw instead of being truncated.

`Dto` has no transformation API. Use public properties directly, or choose `Data` or `Resource` when output mapping, `Optional` omission, lazy values, or built-in transformation is required.

Data objects do not implement `ArrayAccess`. Read public properties or call `toArray()`.

When dumped, a transformable data object displays the same values as `all()`. Data collections display their values under an `items` key. Internal package state is not included. By default, this dump format is only used in the `local` and `testing` environments. Set `data.var_dumper_caster_mode` to `enabled` to use it everywhere, or `disabled` to dump the complete object.

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

#### Conditional and Loaded Relationships

Use `Lazy::when` to include a value only when a condition passes, or `Lazy::whenLoaded` to include a relationship only when it is already loaded:

```php
$profile = Lazy::when(
    fn (): bool => $user->is_public,
    fn (): ProfileData => ProfileData::from($user->profile),
);

$songs = Lazy::whenLoaded(
    'songs',
    $album,
    fn () => SongData::collect($album->songs),
);
```

The condition decides whether these values appear; `include()` and `exclude()` do not change it. Use `except()` to hide one. To include an ordinary lazy value by default, call `defaultIncluded()`:

```php
$profile = Lazy::create(fn (): ProfileData => ProfileData::from($user->profile))
    ->defaultIncluded();
```

#### Automatic Lazy Properties

Add `#[AutoLazy]` to a property, or to the class for every `Lazy` property, to let `from()` wrap supplied values automatically. Use `#[AutoWhenLoadedLazy]` for model relationships:

```php
use Hypervel\Data\Attributes\AutoWhenLoadedLazy;

class UserData extends Data
{
    public function __construct(
        public string $name,
        #[AutoWhenLoadedLazy]
        public Lazy|ProfileData $profile,
    ) {
    }
}
```

The relation defaults to the property name. Pass a name such as `#[AutoWhenLoadedLazy('userProfile')]` when it differs.

`Lazy::closure` and `#[AutoClosureLazy]` return a closure for consumers that understand callback values, such as [Inertia](#inertia).

Automatic lazy values postpone creating their nested values until they are included, unless validation needs them first.

When you create a custom `AutoLazy` attribute, its `build()` method receives the original source that supplied the property. If a validation hook changes the property, the method receives the payload returned by that hook instead. `AutoWhenLoadedLazy` requires an Eloquent model and throws an exception when no model source is available.

<a name="including-and-excluding-properties"></a>
### Including and Excluding Properties

Use `include` and `exclude` to control lazy properties, and `only` and `except` to select which properties appear in output:

```php
return $user
    ->include('profile.avatar')
    ->only('name', 'profile.*')
    ->except('profile.internalNotes')
    ->toArray();
```

Paths use PHP property names, even when output names are mapped. Dot notation selects nested properties; `profile.{name,avatar}` selects a group, and a terminal `*` selects the complete subtree. `only` and `except` take precedence over lazy inclusion.

The ordinary methods apply to the next transformation. Their `Permanently` variants apply to every transformation of that object, and the `When` variants accept a boolean or closure condition:

```php
$user->includePermanently('profile');
$user->exceptWhen('email', ! $canViewEmail);
```

For class-wide defaults, return paths or a map of paths to conditions from `includeProperties()`, `excludeProperties()`, `onlyProperties()`, or `exceptProperties()`:

```php
protected function includeProperties(): array
{
    return ['profile'];
}
```

A malformed path, such as one with an empty segment, always throws an exception. A name that matches no property selects nothing, while a nested path through a property the object does not have throws an exception. Set the `data.ignore_invalid_partials` configuration option to `true` to skip those nested paths instead.

Selections owned by nested objects and collection items are composed with selections from their parent. An item read from a collection by key or in a loop also carries the collection's selections, so `$songs->include('artist')` applies to `$songs[0]->toArray()`. Temporary selections are consumed only when that object is actually reached; collection reads and iteration do not consume them.

<a name="hidden-computed-and-appended-values"></a>
### Hidden, Computed, and Appended Values

`#[Hidden]` omits a declared property from ordinary output. `#[Computed]` marks an output-only property whose value is set by the class; caller input for it is rejected. To ignore that input instead, set the `data.features.ignore_exception_when_trying_to_set_computed_property_value` configuration option to `true`. PHP 8.4 virtual properties are treated as output-only in the same way.

Return additional output values from `with()` or add them to one object with `additional()`:

```php
public function with(): array
{
    return ['links' => ['self' => route('users.show', $this->id)]];
}

return $user->additional(['meta' => ['version' => 1]]);
```

These values appear in transformed output and HTTP responses but are not stored by Eloquent. Values may also be closures receiving the data object, such as `fn (UserData $user): string => $user->name`.

<a name="collections"></a>
## Collections

Use `collect()` to create several objects while preserving supported source shapes and keys:

```php
use Hypervel\Data\DataCollection;
use Hypervel\Support\Collection;

$users = UserData::collect($rows);
$dataCollection = UserData::collect($rows, DataCollection::class);
$collection = UserData::collect($rows, Collection::class);
$array = UserData::collect($rows, 'array');
```

The `$into` argument accepts `null`, `'array'`, or a class name. With no target, arrays remain arrays and ordinary collections remain collections. Eloquent collections become base support collections because data objects are not Eloquent models. Paginators retain their pagination details; an array or collection cannot become a paginator because it has no such details.

Lazy collections remain lazy unless validation needs to read their values. When a target comes from configuration, give it a `class-string` type so static analysis can infer the return type.

Collecting `null` items into an `'array'` or collection target returns that target empty, which suits optional relations and missing input. Without a target, or into a paginator target, `null` is rejected.

`DataCollection`, `PaginatedDataCollection`, and `CursorPaginatedDataCollection` provide typed items, transformation, and response behavior. `DataCollection` also provides keyed access when its underlying collection supports it. Use `toCollection()` for map, filter, reduce, and other collection operations. Paginated data collections cannot be stored directly by Eloquent because their pagination details cannot be recreated from a JSON array. Store their items through a `DataCollection` instead.

```php
$names = $dataCollection->toCollection()->map(fn (UserData $user): string => $user->name);

foreach ($dataCollection as $user) {
    // Work with each UserData object...
}
```

When a source remains lazy, every traversal creates its items again, and calling `count()` also traverses the source. If you need the items more than once, collect them into an eager collection and reuse it:

```php
$eagerUsers = $dataCollection->toCollection()->collect();
```

When validation is enabled, Hypervel validates the complete collection at once so collection rules and hooks can work with the entire payload.

<a name="http-resources"></a>
## HTTP Resources

Return `Data`, `Resource`, or their collection wrappers directly from a controller:

```php
return UserData::from($user);

return UserData::collect($users, DataCollection::class);
```

<a name="wrapping"></a>
### Wrapping

Responses use Hypervel's JSON resources. To wrap the output in a `data` key, call `wrap`:

```php
return UserData::from($user)->wrap('data');

// {"data": {"name": "Taylor", ...}}
```

Call `withoutWrapping()` to disable wrapping for an object or collection. Define `defaultWrap(): string` on a class, or set `data.wrap` in configuration, to choose a default.

Wrapping applies to responses; `toArray()` and `toJson()` don't wrap, apart from paginated output, described below. Within a wrapped response, a nested data object is never wrapped. A data collection keeps its own wrapper when it is a property of the returned data object, but not when it sits inside a nested data object or a collection item. A nested array or collection of data objects uses the `data.wrap` key.

<a name="paginated-responses"></a>
### Paginated Responses

Pass a paginator to `collect` and choose the corresponding data collection:

```php
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\PaginatedDataCollection;

return UserData::collect(User::paginate(), PaginatedDataCollection::class);

return UserData::collect(User::cursorPaginate(), CursorPaginatedDataCollection::class);
```

A paginated data collection, or a paginator property on a data object, is always wrapped, using `data` when no wrapper is set. Its `links` and `meta` describe the current page. Responses, `toArray()`, and `toJson()` produce the same shape. For example, a single-page result with one user contains:

```json
{
    "data": [{"name": "Taylor"}],
    "links": [
        {"url": null, "label": "&laquo; Previous", "page": null, "active": false},
        {"url": "https://example.com/users?page=1", "label": "1", "page": 1, "active": true},
        {"url": null, "label": "Next &raquo;", "page": null, "active": false}
    ],
    "meta": {
        "current_page": 1,
        "first_page_url": "https://example.com/users?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "https://example.com/users?page=1",
        "next_page_url": null,
        "path": "https://example.com/users",
        "per_page": 15,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

A length-aware paginator's `links` holds its page links. A cursor paginator has no page links, and its `meta` holds `path`, `per_page`, `next_cursor`, `next_page_url`, `prev_cursor`, and `prev_page_url`. A simple paginator's `meta` holds the values from its `toArray()` other than its items.

<a name="customizing-responses"></a>
### Customizing Responses

Override static `jsonOptions()` or `withResponse(Request $request, JsonResponse $response)` to customize the response. A custom data collection class may also override `withResponse()`, while its JSON options come from its data class:

```php
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;

public function withResponse(Request $request, JsonResponse $response): void
{
    $response->headers->set('X-API-Version', '1');
}
```

Responses default to `200` for every request method. Set `201` when an action actually creates something:

```php
return UserData::from($user)->toResponse($request)->setStatusCode(201);
```

<a name="selecting-properties-from-requests"></a>
### Selecting Properties From Requests

You may let clients choose lazy properties through the query string. On the data class, declare which properties may be included:

```php
public static function allowedRequestIncludes(): ?array
{
    return ['profile'];
}
```

A request to `/users/1?include=profile` now includes the lazy profile. Multiple names may be comma-separated or supplied as `include[]=profile&include[]=roles`.

The equivalent methods for `exclude`, `only`, and `except` are `allowedRequestExcludes()`, `allowedRequestOnly()`, and `allowedRequestExcept()`. Each defaults to an empty array, disabling that operation. Return `null` to allow all properties. Lists use PHP property names; requests may use PHP or mapped output names. Nested data classes must also allow their requested properties.

<a name="form-request-casting"></a>
## Form Request Casting

Declare a `Data`, `Dto`, or `Resource` class directly in a form request's protected `casts` method. The request creates the object after validation and returns it from `validated()` and `safe()`:

```php
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

The object is built through its normal `from()` method. A present `null` remains `null`, and a missing input is not added to the validated result. Direct `Data`, `Dto`, and `Resource` request casts do not accept cast arguments; Eloquent-only options such as `default` and `encrypted` do not apply here.

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

Assign an object or its input array, then save the model as usual:

```php
$user->profile = UserProfileData::from($profile);
$user->save();

$user->refresh()->profile; // UserProfileData
```

A data collection attribute may be assigned a data collection, or an array, collection, or other `Arrayable` value containing data objects or input arrays.

Eloquent stores all values needed to recreate the data object using its PHP property names. Hidden properties are stored, while computed, virtual, appended, and response-only values are omitted. Partial selections do not change the stored value and are not consumed. Output transformers still run, so a one-way transformer needs a matching input cast or `WithCastAndTransformer` to recreate the original value.

Conditional and relation lazy values must already be included when the model is saved, and saving never loads a relation. Closure and Inertia lazy values cannot be stored because they do not resolve to ordinary data values.

<a name="eloquent-defaults-and-encryption"></a>
### Defaults and Encryption

A database `null` normally remains `null`. Add `default` to create an object with its declared defaults, or an empty collection. Use `encrypted` to encrypt the stored value:

```php
protected function casts(): array
{
    return [
        'profile' => UserProfileData::class . ':default',
        'members' => DataCollection::class . ':' . MemberData::class . ',default,encrypted',
    ];
}
```

The data class must be constructable without input for `default` to succeed. Encrypted values need a text column large enough for the ciphertext, as described in [encrypted casting](/docs/{{version}}/eloquent-mutators#encrypted-casting).

<a name="eloquent-abstract-classes"></a>
### Abstract Classes

A value cast to an abstract data class is stored with the class name of its concrete subtype. Registering aliases stores short names instead, which stay valid when classes are renamed:

```php
use Hypervel\Data\Support\DataConfig;

public function boot(DataConfig $data): void
{
    $data->enforceMorphMap([
        'card' => CardSettingsData::class,
        'bank' => BankSettingsData::class,
    ]);
}
```

Morph maps are boot-time configuration. A stored alias or class name must resolve to a concrete subtype of the cast's abstract class; anything else is rejected when the value is read.

An abstract class implementing [`PropertyMorphableData`](#abstract-data-objects) instead uses its own stored properties to select the subtype. It does not store a separate class name or alias.

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

The contextual value always takes precedence over input and creation hooks, even when it is `null`. For example, client input cannot replace the current user's ID. Values are converted to the declared type, so a `'123'` route parameter becomes an `int`.

When validating a promoted property, Hypervel resolves its contextual value before validation. Its own rules and other rules referencing it see that value. Without validation, resolution happens during construction. Input supplied for a promoted contextual property is ignored, including under strict unknown-field validation. If a rule such as `exclude_if` excludes the contextual value, the property is treated as missing.

A non-promoted contextual parameter is passed only to the constructor. When input should take precedence, omit the contextual attribute and use a named factory or creation hook.

`CurrentUser`, `RouteParameter`, and `Give` accept an optional `property` path and use `data_get()` semantics. Accessors and Eloquent relations may run while traversing that path. `RequestAttribute` selects an exact request attribute key. The `Config`, `Context`, and `Give` attributes are also supported, as are custom contextual attributes.

<a name="inertia"></a>
## Inertia

When `hypervel/inertia` is installed, Data lazy values can produce Inertia props:

```php
use Hypervel\Data\Lazy;
use Hypervel\Inertia\Inertia;

class DashboardData extends Data
{
    public function __construct(
        public Lazy|ProfileData $profile,
        public Lazy|ActivityData $activity,
    ) {
    }
}

$data = new DashboardData(
    Lazy::inertia(fn () => ProfileData::from($profile)),
    Lazy::inertiaDeferred(fn () => ActivityData::from($activity), group: 'activity'),
);

return Inertia::render('Dashboard', $data);
```

`Lazy::inertia()` omits the property on the initial visit and evaluates it when requested in a partial reload. `Lazy::closure()` is included on the initial visit and evaluated only when needed on partial reloads. `Lazy::inertiaDeferred()` loads the property after the initial render; properties with the same group load together.

`#[AutoInertiaLazy]`, `#[AutoClosureLazy]`, and `#[AutoInertiaDeferred('activity')]` provide automatic variants. Existing `DeferProp` instances retain their merge, caching, grouping, and rescue settings. See the [frontend documentation](/docs/{{version}}/frontend) for more on Inertia.

<a name="saloon"></a>
## Saloon

Hypervel Saloon may return a Data object directly from `createDtoFromResponse`:

```php
use Hypervel\Data\Data;
use Hypervel\Saloon\Contracts\DataObjects\WithResponse;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Responses\HasResponse;

class GitHubUserData extends Data implements WithResponse
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

<a name="lightweight-data-objects"></a>
## Lightweight Data Objects

The `Hypervel\Support\DataObject` class provides a small mapper for internal message envelopes, per-item value objects, and other trusted values used in performance-sensitive code. It does not provide validation, property mapping, lazy properties, partials, resources, or persistence. Use `Data`, `Dto`, or `Resource` when you need those features.

To define a lightweight data object, extend `DataObject` and promote every constructor parameter as a public property:

```php
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\DataObject;

class MessageEnvelope extends DataObject
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

<a name="worker-lifetime"></a>
## Worker Lifetime

Hypervel analyzes each data class when it is first used and keeps that description for the worker lifetime. The cached description never contains request data or values from a data object. There is no metadata cache command to run when deploying your application.

Register `Lazy`, `DataCollection`, `PaginatedDataCollection`, and `CursorPaginatedDataCollection` macros during provider boot. These macros remain registered for the worker lifetime, so they must not contain request-specific callbacks or values. Configure morph aliases during boot for the same reason.

<a name="credits"></a>
## Credits

Hypervel Data began as a port of [Spatie Laravel Data](https://github.com/spatie/laravel-data) and has been adapted for Hypervel's framework architecture and coroutine runtime.
