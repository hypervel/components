# Porting from Laravel

## Tests

### Directory Structure

Ported Laravel tests live in `tests/{PackageName}/Laravel/` subdirectories. This separation:
- Makes it easy to diff against Laravel's test suite to identify missing tests
- Keeps Hypervel-specific tests separate from compatibility tests
- Allows running Laravel-ported tests independently

### Base Classes

Two TestCase options:

| Class | Use When |
|-------|----------|
| `Hypervel\Tests\TestCase` | Unit tests, mocks only, no container needed |
| `Hypervel\Testbench\TestCase` | Integration tests, needs container (facades like Date, Config) |

Always call `parent::setUp()` in your setUp method.

### Coroutine-Dependent Code

Code that uses `Context` for state (like `DatabaseTransactionsManager`) requires tests to run in coroutines. Without this, Context state persists across tests since they share the non-coroutine context.

**Add the `RunTestsInCoroutine` trait:**
```php
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;

class MyTest extends TestCase
{
    use RunTestsInCoroutine;
}
```

Each test runs in a fresh coroutine. Context is automatically destroyed when the coroutine ends—no manual cleanup needed.

**Optional hooks** (define if needed):
- `setUpInCoroutine()` - runs inside the coroutine before the test
- `tearDownInCoroutine()` - runs inside the coroutine after the test

### Namespace Changes

- Change `Illuminate\` to `Hypervel\`
- Change `namespace Illuminate\Tests\{Package}` to `namespace Hypervel\Tests\{Package}\Laravel`
- Add `declare(strict_types=1);` at the top of every file

### Stricter Typing

Hypervel uses stricter types than Laravel. This exposes incomplete test mocks that Laravel's loose typing silently accepts.

**Model properties require type declarations:**
```php
// Laravel
protected $table = 'users';
protected $fillable = ['name'];
public $timestamps = false;

// Hypervel
protected ?string $table = 'users';
protected array $fillable = ['name'];
public bool $timestamps = false;
```

**Mock return types must match:**
```php
// Laravel (loose - stdClass works)
$connection = m::mock(stdClass::class);

// Hypervel (strict - use correct type)
$connection = m::mock(PDO::class);
$query = m::mock(QueryBuilder::class);
```

**Fluent methods need return values:**
```php
// Laravel (null return silently accepted)
$builder->shouldReceive('where')->with(...);

// Hypervel (must return for chaining)
$builder->shouldReceive('where')->with(...)->andReturnSelf();
```

**Mocking methods with `static` return type:**

Methods like `newInstance()` have `static` return type, meaning they must return the same class (or subclass) as the object they're called on. Mockery creates proxy subclasses, so returning the parent class fails:

```php
// FAILS - mock is Mockery_1_MyModel, returning MyModel fails static type
$this->related = m::mock(MyModel::class);
$this->related->shouldReceive('newInstance')->andReturn(new MyModel);

// WORKS - use partial mock and andReturnSelf()
$this->related = m::mock(MyModel::class)->makePartial();
$this->related->shouldReceive('newInstance')->andReturnSelf();

// Test attributes on the mock itself (partial mock has real Model behavior)
$result = $relation->getResults();
$this->assertSame('taylor', $result->username);
```

This is a testing-only issue - the strict types are correct and an improvement. In production code, you never mock Models and call `newInstance()`.

**When `andReturnSelf()` isn't enough:**

If a test needs to verify distinct instances (e.g., `makeMany()` returns different objects), use a concrete test stub instead of mocks:

```php
class EloquentHasManyRelatedStub extends Model
{
    public static bool $saveCalled = false;

    public function newInstance(mixed $attributes = [], mixed $exists = false): static
    {
        $instance = new static;
        $instance->setRawAttributes((array) $attributes, true);
        return $instance;
    }

    public function save(array $options = []): bool
    {
        static::$saveCalled = true;
        return true;
    }
}

// Test verifies real behavior, not mock expectations
$this->assertNotSame($instances[0], $instances[1]);
$this->assertFalse(EloquentHasManyRelatedStub::$saveCalled);
```

Concrete stubs are the correct approach here - they test actual behavior rather than just verifying mocks were called correctly.

### When Tests Expose Source Code Type Errors

If a Laravel test fails with a type error, the source code type may be wrong—not the test. Types should be **correct**, not just strict. A narrow type that doesn't cover all valid cases is incorrect.

**How to identify:**
- Test returns/passes a type that the source code should accept but doesn't
- The type is a parent class of what's currently declared (e.g., `Support\Collection` vs `Eloquent\Collection`)

**How to fix:**
1. Identify all valid types the method can accept/return
2. Use the common base type that covers all cases without being unnecessarily loose
3. Fix the source code, not the test

**Example:** A method returns `Eloquent\Collection` normally, but an `afterQuery` callback can return `Support\Collection`. Since `Eloquent\Collection` extends `Support\Collection`, the correct return type is `Support\Collection`—it covers both cases precisely.

**Wrong approach:** Removing types, using `mixed`, or modifying tests to avoid the type check. These hide the real issue.

### Missing Dependencies

Some test files reference classes defined in other test files. Laravel gets away with this due to test suite load order. Make tests self-contained by defining required classes locally:

```php
// Add at bottom of test file if TestModel is used but not defined
class TestModel extends Model
{
}
```

### Unsupported Features

Remove tests for features Hypervel doesn't support:
- `SqlServerConnector` tests (no SQL Server support)
- Dynamic connections (`DB::build()`, `DB::connectUsing()`) - incompatible with Swoole connection pooling

### Removed Tests

When removing a test that's incompatible with Hypervel, replace it with a comment **in the same position**:

```php
// REMOVED: testMethodName - Reason for removal
```

This preserves the test's location so future diffs against Laravel show intentional removals vs new tests needing porting.

For tests that need work but aren't fundamentally incompatible, move them to the `Todo/` subdirectory with `markTestSkipped()` in setUp.

### Quick Checklist

1. Update namespace to `Hypervel\Tests\{Package}\Laravel`
2. Add `declare(strict_types=1);`
3. Change `Illuminate\` imports to `Hypervel\`
4. Choose correct base TestCase
5. Ensure `parent::setUp()` is called
6. Add type declarations to model properties
7. Fix mock types (PDO, QueryBuilder, Grammar, etc.)
8. Add `->andReturnSelf()` to chained method mocks
9. Define any missing helper classes locally
10. Remove tests for unsupported drivers/features
11. Run tests and fix any remaining type errors
