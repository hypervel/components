# Context
[[toc]]

## Introduction

Context is an important feature in Hypervel. It is used to store states within coroutines. Contexts in different coroutines are isolated, and when a coroutine terminates, its context will be destroyed automatically. You don't need to worry about memory leaks problem in context.

If you're not familiar with the context management in coroutines, you can read [Isolating global variables with a coroutine context manager in Swoole](https://swoolelabs.com/blog/isolating-variables-with-coroutine-context) for more detailed information.

::: important
Do not mutate static variables in coroutines unless you know what you're doing, because static variables are shared by all coroutines. It will cause states bleeding and unpredictable bugs.
:::

## Interacting with Context

### Get Context

The `get` method will return the value of the key, if not exists, it will return the default value.

```php
use Hypervel\Context\Context;

$foo = Context::get('foo', 'bar');

$foo = Context::get('foo', 'bar', $coroutineId);
```

### Set Context

The `set` method will set the value of the key, and return the value of the key.

```php
use Hypervel\Context\Context;

// $foo is bar
$foo = Context::set('foo', 'bar');

$foo = Context::set('foo', 'bar', $coroutineId);
```

### Check If Context Key Exists

The `has` method will return true if the key exists, otherwise false.

```php
use Hypervel\Context\Context;

$exists = Context::has('foo');

$exists = Context::has('foo', $coroutineId);
```

### Override Context

Sometimes we need to check if a specific key exists. If the key exists, override the value of a key in the context. You may do so using the `override` method.

```php
use Hypervel\Context\Context;

$request = Context::override(ServerRequestInterface::class, function (ServerRequestInterface $request) {
    return $request->withAddedHeader('foo', 'bar');
});
```

::: note
You can pass `coroutineId` to the third parameter to override the context in a specific coroutine.
:::

### Destroy Context

The `destroy` method will delete the value of the key in coroutine context.

```php
use Hypervel\Context\Context;

Context::destroy('foo');

Context::destroy('foo', $coroutineId);
```

### Destroy All Context

The `destroyAll` method will delete all the values in coroutine context.

```php
use Hypervel\Context\Context;

Context::destroyAll('foo');

Context::destroyAll('foo', $coroutineId);
```

### Get or Set Context

The `getOrSet` method will return the value of the key, if not exists, it will set the value of the key and return.

```php
use Hypervel\Context\Context;

$foo = Context::getOrSet('foo', 'bar');

$foo = Context::getOrSet('foo', 'bar', $coroutineId);
```

### Copy Context

The `copy` method will copy the context from another coroutine to the current coroutine.

```php
use Hypervel\Context\Context;

Context::copy('foo', $fromCoroutineId, $onlyKeys);
```

### Copy Context From Non Coroutine Environment

For compatibility,`Context` also works in non-coroutine environment. All the non-coroutine environment will share the same context copy.

The `copyFromNonCoroutine` method will copy the context from non-coroutine environment to current coroutine.

```php
use Hypervel\Context\Context;

Context::copy('copyFromNonCoroutine', $keys);

Context::copy('copyFromNonCoroutine', $keys, $coroutineId);
```