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
    - [Copying From Non-Coroutine Context](#copying-from-non-coroutine-context)
    - [Copying To Non-Coroutine Context](#copying-to-non-coroutine-context)
    - [Reading Non-Coroutine Context](#reading-non-coroutine-context)
    - [Replicable Context Values](#replicable-context-values)
- [Context Containers](#context-containers)
- [Typed Context Helpers](#typed-context-helpers)
    - [Request Context](#request-context)
    - [Response Context](#response-context)
    - [Parent Coroutine Context](#parent-coroutine-context)
- [Common Pitfalls](#common-pitfalls)

<a name="introduction"></a>
## Introduction

Hypervel stores request, command, job, and framework state in coroutine-local context. Values stored in one coroutine are isolated from values stored in another coroutine, even when both coroutines are running inside the same long-lived Swoole worker. When a coroutine ends, its coroutine context is destroyed with it.

This isolation is important because worker processes are shared by many concurrent requests and jobs. Request-specific state should not be stored in global variables, mutable static properties, or shared singleton object properties. Store that state in `Hypervel\Context\CoroutineContext` instead.

> [!WARNING]
> Static properties and singleton object properties are shared by every coroutine in the worker. Mutating them with request-specific data can leak state between concurrent requests.

<a name="coroutine-context-and-application-context"></a>
### Coroutine Context and Application Context

`CoroutineContext` is the low-level coroutine-local key / value store used by Hypervel framework and package code. It is different from the Laravel-style `Hypervel\Support\Facades\Context` facade and the `context()` helper documented in the [context](/docs/{{version}}/context) documentation.

Most application code that wants to add metadata to logs, queued jobs, and cross-boundary execution should use the `Context` facade. Package and framework code may use `CoroutineContext` directly when it needs low-level coroutine-local storage.

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

An explicit coroutine ID always targets only that coroutine, regardless of whether the caller is running inside another coroutine. If the requested coroutine does not exist, `set` throws a `Hypervel\Engine\Exceptions\CoroutineDestroyedException`. In the same situation, `get` returns its default, `has` returns `false`, `forget` and `flush` do nothing, and `getContainer` returns `null`. These operations never fall back to the shared non-coroutine context store when an explicit ID is supplied.

<a name="determining-item-existence"></a>
### Determining Item Existence

The `has` method determines if a non-null value exists for the given key:

```php
use Hypervel\Context\CoroutineContext;

if (CoroutineContext::has('tenant_id')) {
    // ...
}
```

You may pass a coroutine ID as the second argument:

```php
$exists = CoroutineContext::has('tenant_id', $coroutineId);
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

When `flush` is called outside a coroutine, it clears Hypervel's non-coroutine context store.

<a name="overriding-values"></a>
### Overriding Values

The `override` method retrieves the current value, passes it to a closure, stores the closure's return value, and returns the new value:

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

The `getOrSet` method retrieves an existing non-null value. If the key does not exist, the value will be stored and returned:

```php
use Hypervel\Context\CoroutineContext;

$tenantId = CoroutineContext::getOrSet('tenant_id', 123);
```

You may pass a closure as the value. The closure is only executed when the key is missing:

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

Child coroutines do not copy the parent coroutine context by default. You may copy context explicitly using `CoroutineContext::copyFrom`, or by using the `copyContext` argument on coroutine helpers such as `go`, `co`, and `parallel`.

<a name="copying-from-another-coroutine"></a>
### Copying From Another Coroutine

The `copyFrom` method copies context values from another coroutine into the current coroutine.

In normal Hypervel request, command, job, and test code, your code is already running inside a coroutine:

```php
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\wait;

CoroutineContext::set('request_id', 'abc');

$parentId = Coroutine::id();

$requestId = wait(function () use ($parentId) {
    CoroutineContext::copyFrom($parentId);

    return CoroutineContext::get('request_id');
});
```

You may copy only specific keys:

```php
CoroutineContext::copyFrom($parentId, ['request_id']);
```

Copied values are merged into the current coroutine context. Existing values that do not share a copied key are preserved, while matching keys are overwritten.

> [!NOTE]
> `copyFrom` copies from another coroutine's context. It does not copy values from the non-coroutine context store.

<a name="copying-from-non-coroutine-context"></a>
### Copying From Non-Coroutine Context

When `CoroutineContext` is used outside a coroutine, values are stored in a shared non-coroutine context store. The `copyFromNonCoroutine` method copies those values into an existing coroutine context:

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

Hypervel's test infrastructure uses the `copyToNonCoroutine` method to bridge selected state from a test coroutine into PHPUnit lifecycle code that runs outside a coroutine. You may select specific keys and, when necessary, the source coroutine:

```php
use Hypervel\Context\CoroutineContext;

CoroutineContext::copyToNonCoroutine(['test_state'], $coroutineId);
```

> [!WARNING]
> `copyToNonCoroutine` writes to process-global storage shared by every coroutine in the worker. It is intended only for controlled test lifecycle bridges and must not be used to propagate request state.

<a name="reading-non-coroutine-context"></a>
### Reading Non-Coroutine Context

The `getFromNonCoroutine` method always reads from the non-coroutine context store, even when called inside a coroutine:

```php
use Hypervel\Context\CoroutineContext;

$requestId = CoroutineContext::getFromNonCoroutine('request_id');
```

Test infrastructure may clear its owned keys from the non-coroutine context store using `clearFromNonCoroutine`:

```php
CoroutineContext::clearFromNonCoroutine(['test_state']);
```

> [!WARNING]
> `clearFromNonCoroutine` mutates process-global storage and is intended only for controlled test lifecycle cleanup.

<a name="replicable-context-values"></a>
### Replicable Context Values

When context is copied between coroutines, object values are shared by reference by default. If you need an object to be copied independently, implement the `Hypervel\Context\ReplicableContext` interface:

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

Objects implementing `ReplicableContext` are copied by calling their `replicate` method when `CoroutineContext::copyFrom` or `CoroutineContext::copyFromNonCoroutine` copies them.

<a name="context-containers"></a>
## Context Containers

The `getContainer` method returns the raw context storage for the current or specified coroutine:

```php
use Hypervel\Context\CoroutineContext;

$container = CoroutineContext::getContainer();
```

Inside a coroutine, this method returns the coroutine's `ArrayObject` context storage. Outside a coroutine, it returns the non-coroutine context array. If the requested coroutine context does not exist, `null` is returned.

This method is intended for low-level framework and package code. Most code should use `get`, `set`, `has`, and `forget` instead of mutating the raw context container directly.

<a name="typed-context-helpers"></a>
## Typed Context Helpers

Hypervel includes a few small typed helpers built on top of `CoroutineContext`. These helpers are mostly useful in framework and package code that needs direct access to low-level request or response state.

<a name="request-context"></a>
### Request Context

The `RequestContext` class stores the current `Hypervel\Http\Request` instance:

```php
use Hypervel\Context\RequestContext;

RequestContext::set($request);

$request = RequestContext::get();
```

You may check, remove, or optionally retrieve the current request:

```php
if (RequestContext::has()) {
    $request = RequestContext::getOrNull();
}

RequestContext::forget();
```

Each method accepts an optional coroutine ID when you need to access another coroutine's request context.

<a name="response-context"></a>
### Response Context

The `ResponseContext` class stores the current `Hypervel\Http\Response` instance:

```php
use Hypervel\Context\ResponseContext;

ResponseContext::set($response);

$response = ResponseContext::get();
```

You may check, remove, or optionally retrieve the current response:

```php
if (ResponseContext::has()) {
    $response = ResponseContext::getOrNull();
}

ResponseContext::forget();
```

Each method accepts an optional coroutine ID when you need to access another coroutine's response context.

<a name="parent-coroutine-context"></a>
### Parent Coroutine Context

The `ParentCoroutineContext` class reads and writes values in the parent coroutine's context when called from inside a child coroutine:

```php
use Hypervel\Context\ParentCoroutineContext;

ParentCoroutineContext::set('request_id', 'abc');

$requestId = ParentCoroutineContext::get('request_id');
```

It provides `set`, `get`, `has`, `forget`, `override`, `getOrSet`, and `getContainer` methods. When called outside a coroutine, these methods operate on the current non-coroutine context instead.

<a name="common-pitfalls"></a>
## Common Pitfalls

The `context()` helper and `Hypervel\Support\Facades\Context` are not shortcuts for `CoroutineContext::get` and `CoroutineContext::set`. They use Hypervel's application-facing context repository for log metadata and cross-boundary context propagation. Use `CoroutineContext` only when you need low-level coroutine-local storage.

Values set outside a coroutine are stored in the shared non-coroutine context store. They are not automatically copied into child coroutines created with `go`, `co`, or `parallel`. Use `copyFromNonCoroutine` when you explicitly need to copy those values into a coroutine.

Values set inside one coroutine are not visible inside another coroutine unless you copy them. Use `Coroutine::fork`, `go(..., copyContext: true)`, `parallel(..., copyContext: true)`, or `CoroutineContext::copyFrom(...)` when child coroutines need access to parent context values.

Avoid storing mutable request-specific objects in coroutine context and then copying them into child coroutines unless shared mutation is intentional. Implement `ReplicableContext` for mutable objects that should be copied independently.
