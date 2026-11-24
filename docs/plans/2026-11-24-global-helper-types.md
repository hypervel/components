# Complete the native types of global helpers

## Contract and scope

Every global function in the Collections, Support and Foundation helper files must have native parameter and return types. Keep parameter names, defaults, variadics, reference passing, and supported Laravel behavior. Use `mixed` for arbitrary values, such as container bindings and callback results. Keep PHPDoc where it describes types more precisely than PHP can. Remove the corresponding framework-wide TODO once the complete change is verified.

## Collections

- Keep `collect()` and the eager and lazy constructors compatible with the values their implementations already accept. `getArrayableItems()` wraps scalars and enum cases, accepts null and delegates other inputs to `Arr::from()`. The shared collection tests cover scalars, objects, null, and both construction and `make()`. Dedicated tests construct and collect enum cases. Keep the lazy collection's closure/self handling and its exception for a directly supplied Generator. Preserve the runtime error for unsupported resources.
- Widen the input PHPDocs and use native `mixed` for the eager constructor, helper and `Enumerable::make()`. The concrete factories already accept `mixed`. Laravel's interface accepts an untyped argument. A custom implementation written against Hypervel's former narrower interface signature must widen that parameter. The framework and private packages contain no such implementation.
- Infer keys and values at the input boundary: use explicit generic Arrayable and iterable input types plus a method-local object template for other objects. A plain `object` union would swallow the specialized inputs. The return is simply `Collection<TKey, TValue>` or `static<TMakeKey, TMakeValue>`. Unknown keys can be integers or strings, including property names from plain objects. The existing scalar inference extension is sufficient. Preserve keyed arrays, generic Arrayable and Traversable inputs, lazy generator factories and inferred empty collections. The bleeding-edge suite has cases where later calls to `push()`, `put()` or `merge()` determine an empty collection's types.
- Preserve by-reference targets in `data_fill()`, `data_set()` and `data_forget()`. Use `mixed` for arbitrary targets and values, the declared array/int/string key types, and nullable keys on get, has, and forget. Only the overwrite flag on set is boolean. Type `value()` and `when()` without changing which callbacks are executed.
- For `head()` and `last()`, use native `(array $array): mixed`. Their PHPDoc tracks the element type and distinguishes an empty array:

```php
/**
 * @template TValue
 *
 * @param array<array-key, TValue> $array
 * @return ($array is array{} ? false : TValue)
 */
```

## Support

Match class and trait helpers to their object/class-name inputs, preserving the tested integer input to `class_basename()`. `fluent()` accepts arrays, objects and null. `object_get()` takes an object and a bool/int/string/null key, including the tested false key. Keys or targets that were only incidentally coerced outside the declared contracts may now raise TypeError, as may falsy non-arrays passed to `head()` or `last()`.

Use native `mixed` on genuinely unconstrained callback inputs and outputs. Keep generic and conditional PHPDocs for `once()`, `tap()`, `optional()`, `with()`, and conditional throws. Match `retry()` to its integer/array attempts, CarbonInterval/Closure/float/int delay and optional callable predicate. `str()` returns a native Stringable both for its anonymous proxy and its concrete string wrapper. Preserve the distinction between no argument and explicit null.

Correct `transform()`'s return PHPDoc using `blank()`'s semantics. Null, the empty string, and an empty array select the default. Numeric and boolean values, nonempty arrays, and Eloquent models select the callback. Other strings can be whitespace, and Countable or Stringable objects can be blank, so their static types can yield either result. Keep both upstream type fixture suites. Use a zero-value assertion to distinguish this contract from PHP's `empty()`.

## Foundation and exception reporting

Keep `app()`, `resolve()`, `policy()` and `context()` polymorphic. Context accepts array/string/null keys and dispatch passes arbitrary jobs to the existing pending dispatch types. Preserve type extension and class-string inference.

On `rescue()`'s exception path, evaluate a boolean report flag directly or invoke its callable with the exception. Previously an array callable returning false was treated as a truthy value and the exception was reported anyway. Cancellation still propagates first. Keep fallback evaluation through `value()`: a Closure computes the fallback, and other values, including callable strings, are returned as given.

## Verification

Run each edited test file when changed. Reuse the existing helper and collection suites and the Laravel and Larastan type fixtures. Add focused assertions for the empty-array sentinel, valid collection inputs previously rejected, the zero case in transform, and a non-Closure reporting predicate. Verify with reflection that every global function defined by all three files has native parameter and return types. Run CS Fixer, full PHPStan including type/test/skeleton configurations, then targeted runtime and analyzer tests, including bleeding-edge collection usage inference. Review the final diff and affected callers, obtain peer code-review signoff, and commit whole files with the TODO removal in the completed helper work.
