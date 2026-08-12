# Coroutine Context

- [Introduction](#introduction)
    - [Coroutine Context and Application Context](#coroutine-context-and-application-context)
- [Interacting With Context](#interacting-with-context)
    - [Retrieving Values](#retrieving-values)
    - [Storing Values](#storing-values)
    - [Determining Item Existence](#determining-item-existence)
    - [Removing Values](#removing-values)
    - [Flushing Context](#flushing-context)
    - [Overriding Values](#overriding-values)
    - [Retrieving or Storing Values](#retrieving-or-storing-values)
    - [Storing Multiple Values](#storing-multiple-values)
    - [Enum Keys](#enum-keys)
- [Copying Context](#copying-context)
    - [Copying From Another Coroutine](#copying-from-another-coroutine)
    - [Capturing Context Values](#capturing-context-values)
    - [Copying From Non-Coroutine Context](#copying-from-non-coroutine-context)
    - [Copying To Non-Coroutine Context](#copying-to-non-coroutine-context)
    - [Reading Non-Coroutine Context](#reading-non-coroutine-context)
    - [Replicable Context Values](#replicable-context-values)
- [Context Containers](#context-containers)
- [Typed Context Helpers](#typed-context-helpers)
    - [Request Context](#request-context)
    - [Parent Coroutine Context](#parent-coroutine-context)
- [Common Pitfalls](#common-pitfalls)

<a name="introduction"></a>
## Introduction

Hypervel stores state for each request, command, queued job, and other framework task in coroutine context. Each coroutine has its own context values, even when several coroutines are running inside the same long-lived Swoole worker. When a coroutine ends, its context values are removed automatically.

This isolation prevents state from leaking between requests and jobs that run in the same worker. Store request-specific state in `Hypervel\Context\CoroutineContext` instead of global variables, mutable static properties, or shared singleton object properties.

> [!WARNING]
> Static properties and singleton object properties are shared by every coroutine in the worker. Mutating them with request-specific data can leak state between concurrent requests.

<a name="coroutine-context-and-application-context"></a>
### Coroutine Context and Application Context

`CoroutineContext` is the low-level key / value store used by Hypervel framework and package code. It is different from the Laravel-style `Hypervel\Support\Facades\Context` facade and the `context()` helper described in the [context documentation](/docs/{{version}}/context).

Most application code should use the `Context` facade when adding information to logs or passing it to queued jobs. Framework and package code may use `CoroutineContext` directly when it needs to store low-level state for the current coroutine.

<a name="interacting-with-context"></a>
## Interacting With Context

<a name="retrieving-values"></a>
### Retrieving Values

You may retrieve a value from the current coroutine context using the `get` method. If the key does not exist, the default value will be returned:

```php
use Hypervel\Context\CoroutineContext;

$tenantId = CoroutineContext::get('tenant_id');

$tenantId = CoroutineContext::get('tenant_id', default: 1);
```

You may pass a coroutine ID as the third argument to read from a specific coroutine's context:

```php
$tenantId = CoroutineContext::get('tenant_id', null, $coroutineId);
```

<a name="storing-values"></a>
### Storing Values

The `set` method stores a value in the current coroutine context and returns the value:

```php
use Hypervel\Context\CoroutineContext;

$tenantId = CoroutineContext::set('tenant_id', 123);
```

You may pass a coroutine ID as the third argument to store a value in a specific coroutine's context:

```php
CoroutineContext::set('tenant_id', 123, $coroutineId);
```

When you pass a coroutine ID, Hypervel only uses that coroutine's context. It never falls back to the shared non-coroutine context.

If the coroutine does not exist, `set` throws a `Hypervel\Engine\Exceptions\CoroutineDestroyedException`. The `get` method returns its default value, `has` returns `false`, `forget` and `flush` do nothing, and `getContainer` returns `null`.

<a name="determining-item-existence"></a>
### Determining Item Existence

To determine if a non-null value exists for a given key, you may use the `has` method:

```php
use Hypervel\Context\CoroutineContext;

if (CoroutineContext::has('tenant_id')) {
    // ...
}
```

You may pass a coroutine ID as the second argument:

```php
$hasTenantId = CoroutineContext::has('tenant_id', $coroutineId);
```

> [!NOTE]
> `has` uses PHP's `isset` behavior. A key whose value is `null` is treated as missing.

<a name="removing-values"></a>
### Removing Values

The `forget` method removes a value from the context:

```php
use Hypervel\Context\CoroutineContext;

CoroutineContext::forget('tenant_id');
```

You may pass a coroutine ID as the second argument:

```php
CoroutineContext::forget('tenant_id', $coroutineId);
```

<a name="flushing-context"></a>
### Flushing Context

The `flush` method removes all values from the current coroutine context:

```php
use Hypervel\Context\CoroutineContext;

CoroutineContext::flush();
```

You may pass a coroutine ID to flush a specific coroutine's context:

```php
CoroutineContext::flush($coroutineId);
```

When `flush` is called outside a coroutine, it clears the shared non-coroutine context.

<a name="overriding-values"></a>
### Overriding Values

The `override` method passes the current value to a closure. The value returned by the closure is stored in the context and returned by the method:

```php
use Hypervel\Context\CoroutineContext;

CoroutineContext::set('attempts', 1);

$attempts = CoroutineContext::override('attempts', function (?int $attempts) {
    return $attempts + 1;
});
```

If the key does not exist, the closure receives `null`. You may pass a coroutine ID as the third argument:

```php
CoroutineContext::override('attempts', fn (?int $attempts) => $attempts + 1, $coroutineId);
```

<a name="retrieving-or-storing-values"></a>
### Retrieving or Storing Values

The `getOrSet` method returns the existing value for a key. If the key is missing or its value is `null`, the given value is stored and returned:

```php
use Hypervel\Context\CoroutineContext;

$tenantId = CoroutineContext::getOrSet('tenant_id', 123);
```

You may pass a closure as the value. The closure is only executed when the key is missing or its value is `null`:

```php
$tenantId = CoroutineContext::getOrSet('tenant_id', function () {
    return resolveTenantId();
});
```

You may pass a coroutine ID as the third argument:

```php
$tenantId = CoroutineContext::getOrSet('tenant_id', fn () => resolveTenantId(), $coroutineId);
```

<a name="storing-multiple-values"></a>
### Storing Multiple Values

The `setMany` method stores multiple key / value pairs:

```php
use Hypervel\Context\CoroutineContext;

CoroutineContext::setMany([
    'tenant_id' => 123,
    'request_id' => 'abc',
]);
```

You may pass a coroutine ID as the second argument:

```php
CoroutineContext::setMany([
    'tenant_id' => 123,
    'request_id' => 'abc',
], $coroutineId);
```

<a name="enum-keys"></a>
### Enum Keys

Most `CoroutineContext` methods that accept a key may receive a string or enum value. Backed enums use their backing value as the context key, while unit enums use their case name:

```php
use Hypervel\Context\CoroutineContext;

enum ContextKey: string
{
    case CurrentTenant = 'current-tenant';
}

CoroutineContext::set(ContextKey::CurrentTenant, 123);

$tenantId = CoroutineContext::get('current-tenant');
```

<a name="copying-context"></a>
## Copying Context

Child coroutines do not inherit their parent's context values by default. If a child needs these values, you may copy them using `CoroutineContext::copyFrom` or the `copyContext` argument provided by `go`, `co`, and `parallel`.

<a name="copying-from-another-coroutine"></a>
### Copying From Another Coroutine

The `copyFrom` method copies context values from another coroutine into the current one.

Hypervel request, command, job, and test code normally runs inside a coroutine. Before creating a child coroutine, retrieve the current coroutine's ID. The child may then use that ID to copy its parent's context:

```php
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\wait;

CoroutineContext::set('request_id', 'abc');

$parentCoroutineId = Coroutine::id();

$requestId = wait(function () use ($parentCoroutineId) {
    CoroutineContext::copyFrom($parentCoroutineId);

    return CoroutineContext::get('request_id');
});
```

You may copy only specific keys:

```php
CoroutineContext::copyFrom($parentCoroutineId, ['request_id']);
```

Copied values are merged into the current coroutine context. Existing values that do not share a copied key are preserved, while matching keys are overwritten.

> [!NOTE]
> `copyFrom` copies from another coroutine's context. It does not copy values from the non-coroutine context store.

<a name="capturing-context-values"></a>
### Capturing Context Values

The `captureFrom` method returns context values as an array without installing them in another coroutine. By default, it captures every value from the current coroutine. You may pass a list of keys to capture only those values:

```php
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;

$capturedContext = CoroutineContext::captureFrom(['request_id']);

Coroutine::create(function () use ($capturedContext) {
    CoroutineContext::setMany($capturedContext);

    $requestId = CoroutineContext::get('request_id');
});
```

To capture values from another coroutine, pass its ID using the `fromCoroutineId` argument:

```php
$capturedContext = CoroutineContext::captureFrom(
    ['request_id'],
    fromCoroutineId: $parentCoroutineId,
);
```

When a captured value implements `ReplicableContext`, Hypervel calls its `replicate` method. The `copyFrom` method captures every value before changing the current context, so a replication failure leaves the current context unchanged.

The returned array is not serialized. If you install it in another coroutine, objects that do not implement `ReplicableContext` remain shared between the coroutines.

> [!NOTE]
> Most application code should use `Coroutine::fork` or the `copyContext` argument provided by `go`, `co`, and `parallel`. Use `captureFrom` when you need to capture context values now and install them in another coroutine later.

<a name="copying-from-non-coroutine-context"></a>
### Copying From Non-Coroutine Context

When you use `CoroutineContext` outside a coroutine, its values are stored in a shared non-coroutine context. The `copyFromNonCoroutine` method copies these values into a coroutine:

```php
use Hypervel\Context\CoroutineContext;

use function Hypervel\Coroutine\run;

CoroutineContext::set('request_id', 'abc');

run(function () {
    CoroutineContext::copyFromNonCoroutine();

    $requestId = CoroutineContext::get('request_id');
});
```

You may copy only specific keys:

```php
CoroutineContext::copyFromNonCoroutine(['request_id']);
```

You may pass a coroutine ID as the second argument to copy into a specific coroutine:

```php
CoroutineContext::copyFromNonCoroutine(['request_id'], $coroutineId);
```

<a name="copying-to-non-coroutine-context"></a>
### Copying To Non-Coroutine Context

Hypervel's test infrastructure uses the `copyToNonCoroutine` method to make selected state from a test coroutine available to PHPUnit lifecycle code that runs outside a coroutine. You may select specific keys and, when necessary, the source coroutine:

```php
use Hypervel\Context\CoroutineContext;

CoroutineContext::copyToNonCoroutine(['test_state'], $coroutineId);
```

> [!WARNING]
> `copyToNonCoroutine` writes to storage shared by every coroutine in the worker. Use it only for controlled test lifecycle code. Do not use it to copy request state.

<a name="reading-non-coroutine-context"></a>
### Reading Non-Coroutine Context

The `getFromNonCoroutine` method always reads from the shared non-coroutine context, even when called inside a coroutine:

```php
use Hypervel\Context\CoroutineContext;

$requestId = CoroutineContext::getFromNonCoroutine('request_id');
```

Test infrastructure may remove its keys from the non-coroutine context using `clearFromNonCoroutine`:

```php
CoroutineContext::clearFromNonCoroutine(['test_state']);
```

> [!WARNING]
> `clearFromNonCoroutine` changes storage shared by every coroutine in the worker. Use it only for controlled test lifecycle cleanup.

<a name="replicable-context-values"></a>
### Replicable Context Values

When context is copied between coroutines, its objects are shared by default. If each coroutine needs its own copy of an object, implement the `Hypervel\Context\ReplicableContext` interface:

```php
use Hypervel\Context\ReplicableContext;

class RequestState implements ReplicableContext
{
    public function __construct(
        public array $attributes = [],
    ) {
        //
    }

    public function replicate(): static
    {
        return new static($this->attributes);
    }
}
```

When `Coroutine::fork`, `CoroutineContext::captureFrom`, `CoroutineContext::copyFrom`, or `CoroutineContext::copyFromNonCoroutine` encounters one of these objects, Hypervel copies it using the `replicate` method.

<a name="context-containers"></a>
## Context Containers

The `getContainer` method returns the context container for the current or specified coroutine:

```php
use Hypervel\Context\CoroutineContext;

$container = CoroutineContext::getContainer();
```

Inside a coroutine, this method returns the coroutine's `ArrayObject`. Outside a coroutine, it returns an array containing the shared non-coroutine context values. If you request a coroutine that does not exist, the method returns `null`.

This method is intended for low-level framework and package code. Most code should use `get`, `set`, `has`, and `forget` instead.

<a name="typed-context-helpers"></a>
## Typed Context Helpers

Hypervel includes typed helpers for common values stored in `CoroutineContext`. These helpers are mainly intended for framework and package code.

<a name="request-context"></a>
### Request Context

The `RequestContext` class stores the current `Hypervel\Http\Request` instance:

```php
use Hypervel\Context\RequestContext;

RequestContext::set($request);

$request = RequestContext::get();
```

You may determine if a request exists, retrieve it when it is available, or remove it:

```php
if (RequestContext::has()) {
    // ...
}

$request = RequestContext::getOrNull();

RequestContext::forget();
```

Each method accepts an optional coroutine ID when you need to access another coroutine's request context.

<a name="parent-coroutine-context"></a>
### Parent Coroutine Context

The `ParentCoroutineContext` class allows a child coroutine to read or change values in its parent's context:

```php
use Hypervel\Context\ParentCoroutineContext;

ParentCoroutineContext::set('request_id', 'abc');

$requestId = ParentCoroutineContext::get('request_id');
```

The class provides `set`, `get`, `has`, `forget`, `override`, `getOrSet`, and `getContainer` methods. When called outside a coroutine, these methods use the shared non-coroutine context instead.

<a name="common-pitfalls"></a>
## Common Pitfalls

The `context()` helper and `Hypervel\Support\Facades\Context` are not aliases for `CoroutineContext::get` and `CoroutineContext::set`. They manage application context used for features such as log metadata and queued jobs. Use `CoroutineContext` only when you need low-level storage for the current coroutine.

Values set outside a coroutine are stored in the shared non-coroutine context. They are not automatically copied into child coroutines created with `go`, `co`, or `parallel`. Use `copyFromNonCoroutine` when a coroutine needs those values.

Values stored in one coroutine are not visible inside another unless you copy them. Use `Coroutine::fork`, `go(..., copyContext: true)`, `parallel(..., copyContext: true)`, or `CoroutineContext::copyFrom(...)` when a child needs values from its parent.

Objects remain shared when context is copied unless they implement `ReplicableContext`. Avoid copying mutable request-specific objects when shared changes would be unsafe.
