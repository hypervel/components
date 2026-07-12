# Eloquent: Mutators & Casting
[[toc]]

## Introduction

Accessors, mutators, and attribute casting allow you to transform Eloquent attribute values when you retrieve or set them on model instances. For example, you may want to use the [Hypervel encrypter](/docs/encryption) to encrypt a value while it is stored in the database, and then automatically decrypt the attribute when you access it on an Eloquent model. Or, you may want to convert a JSON string that is stored in your database to an array when it is accessed via your Eloquent model.

## Accessors & Mutators

### Defining An Accessor

An accessor transforms an Eloquent attribute value when it is accessed. To define an accessor, create a `get{Attribute}Attribute` method on your model where `{Attribute}` is the "studly" cased name of the column you wish to access.

In this example, we'll define an accessor for the `first_name` attribute. The accessor will automatically be called by Eloquent when attempting to retrieve the value of the `first_name` attribute:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /**
     * Get the user's first name.
     *
     * @param  string  $value
     * @return string
     */
    public function getFirstNameAttribute($value)
    {
        return ucfirst($value);
    }
}
```

As you can see, the original value of the column is passed to the accessor, allowing you to manipulate and return the value. To access the value of the accessor, you may simply access the `first_name` attribute on a model instance:

```php
use App\Models\User;

$user = User::find(1);

$firstName = $user->first_name;
```

You are not limited to interacting with a single attribute within your accessor. You may also use accessors to return new, computed values from existing attributes:

```php
/**
 * Get the user's full name.
 *
 * @return string
 */
public function getFullNameAttribute()
{
    return "{$this->first_name} {$this->last_name}";
}
```

::: tip
If you would like these computed values to be added to the array / JSON representations of your model, [you will need to append them](/docs/eloquent-serialization#appending-values-to-json).
:::

### Defining A Mutator

A mutator transforms an Eloquent attribute value when it is set. To define a mutator, define a `set{Attribute}Attribute` method on your model where `{Attribute}` is the "studly" cased name of the column you wish to access.

Let's define a mutator for the `first_name` attribute. This mutator will be automatically called when we attempt to set the value of the `first_name` attribute on the model:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /**
     * Set the user's first name.
     *
     * @param  string  $value
     * @return void
     */
    public function setFirstNameAttribute($value)
    {
        $this->attributes['first_name'] = strtolower($value);
    }
}
```

The mutator will receive the value that is being set on the attribute, allowing you to manipulate the value and set the manipulated value on the Eloquent model's internal `$attributes` property. To use our mutator, we only need to set the `first_name` attribute on an Eloquent model:

```php
use App\Models\User;

$user = User::find(1);

$user->first_name = 'Sally';
```

In this example, the `setFirstNameAttribute` function will be called with the value `Sally`. The mutator will then apply the `strtolower` function to the name and set its resulting value in the internal `$attributes` array.

## Attribute Casting

The `$casts` property on your model provides a convenient method of converting attributes to common data types. The `$casts` property should be an array where the key is the name of the attribute being cast and the value is the type you wish to cast the column to. The supported cast types are: `integer`, `real`, `float`, `double`, `decimal:<digits>`, `string`, `boolean`, `object`, `array`, `collection`, `date`, `datetime`, and `timestamp`. When casting to `decimal`, you must define the number of digits (`decimal:2`).

To demonstrate attribute casting, let's cast the `is_admin` attribute, which is stored in our database as an integer (`0` or `1`) to a boolean value:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /**
     * The attributes that should be cast.
     */
    protected array $casts = [
        'is_admin' => 'boolean',
    ];
}
```

After defining the cast, the `is_admin` attribute will always be cast to a boolean when you access it, even if the underlying value is stored in the database as an integer:

```php
$user = App\Models\User::find(1);

if ($user->is_admin) {
    //
}
```

If you need to add a new, temporary cast at runtime, you may use the `mergeCasts` method. These cast definitions will be added to any of the casts already defined on the model:

```php
$user->mergeCasts([
    'is_admin' => 'integer',
    'options' => 'object',
]);
```

::: note
Attributes that are `null` will not be cast. In addition, you should never define a cast (or an attribute) that has the same name as a relationship.
:::

### Array & JSON Casting

The `array` cast is particularly useful when working with columns that are stored as serialized JSON. For example, if your database has a `JSON` or `TEXT` field type that contains serialized JSON, adding the `array` cast to that attribute will automatically deserialize the attribute to a PHP array when you access it on your Eloquent model:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /**
     * The attributes that should be cast.
     */
    protected array $casts = [
        'options' => 'array',
    ];
}
```

Once the cast is defined, you may access the `options` attribute and it will automatically be deserialized from JSON into a PHP array. When you set the value of the `options` attribute, the given array will automatically be serialized back into JSON for storage:

```php
use App\Models\User;

$user = User::find(1);

$options = $user->options;

$options['key'] = 'value';

$user->options = $options;

$user->save();
```

### Date Casting

By default, Eloquent will cast the `created_at` and `updated_at` columns to instances of [Carbon](https://github.com/briannesbitt/Carbon), which extends the PHP `DateTime` class and provides an assortment of helpful methods. You may cast additional date attributes by defining additional date casts within your model's `$casts` property array. Typically, dates should be cast using the `datetime` or `immutable_datetime` cast types.

When defining a `date` or `datetime` cast, you may also specify the date's format. This format will be used when the [model is serialized to an array or JSON](/docs/eloquent-serialization):

```php
/**
 * The attributes that should be cast.
 */
protected array $casts = [
    'created_at' => 'datetime:Y-m-d',
];
```

When a column is cast as a date, you may set the corresponding model attribute value to a UNIX timestamp, date string (`Y-m-d`), date-time string, or a `DateTime` / `Carbon` instance. The date's value will be correctly converted and stored in your database.

You may customize the default serialization format for all of your model's dates by defining a `serializeDate` method on your model. This method does not affect how your dates are formatted for storage in the database:

```php
/**
 * Prepare a date for array / JSON serialization.
 *
 * @param  \DateTimeInterface  $date
 * @return string
 */
protected function serializeDate(DateTimeInterface $date)
{
    return $date->format('Y-m-d');
}
```

To specify the format that should be used when actually storing a model's dates within your database, you should define a `$dateFormat` property on your model:

```php
/**
 * The storage format of the model's date columns.
 */
protected string $dateFormat = 'U';
```