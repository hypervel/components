<?php

declare(strict_types=1);

namespace Macros;

use App\Post;
use App\PostBuilder;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Request as RequestFacade;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Tests\PHPStan\Fixtures\Macros\MacroHost;
use Hypervel\Validation\ValidationException;
use PHPStan\TrinaryLogic;
use RuntimeException;
use stdClass;

use function PHPStan\Testing\assertType;
use function PHPStan\Testing\assertVariableCertainty;

function test(): void
{
    try {
        Request::validate([]);
    } catch (ValidationException $e) {
        $foo = 'foo';
    }

    assertType('string', Builder::globalCustomMacro(b: 99));
    assertType('string', Post::globalCustomMacro(b: 99));
    assertType('string', PostBuilder::globalCustomMacro(b: 99));
    assertType('string', User::first()->accounts()->globalCustomMacro(b: 99));

    // Builder::__call() runs a global macro before a scope of the same name.
    assertType('string', ModelWithMacroNamedScope::globalCustomMacro(b: 99));
    assertType('string', ModelWithMacroNamedScope::query()->globalCustomMacro(b: 99));

    // Unlike upstream, Eloquent discards a forwarded query macro's result and returns the builder, which a relation
    // returns as itself. Builder::__callStatic() runs only the builder's own global macros, so the query macro is
    // called on a builder instance here; upstream's static PostBuilder::globalCustomDatabaseQueryMacro() is an error.
    assertType('string', QueryBuilder::globalCustomDatabaseQueryMacro(b: 99));
    assertType('App\PostBuilder<App\Post>', Post::globalCustomDatabaseQueryMacro(b: 99));
    assertType('App\PostBuilder<App\Post>', Post::query()->globalCustomDatabaseQueryMacro(b: 99));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', User::first()->accounts()->globalCustomDatabaseQueryMacro(b: 99));

    assertType('int', Route::facadeMacro());
    // Hypervel: this app's untyped passport guard leaves the current guard unknown, so no guard macro is
    // available through Auth. auth-guards.php covers typed guards.
    assertType('*ERROR*', Auth::sessionGuardMacro());
    assertType('*ERROR*', Auth::requestGuardMacro());

    assertType('string', collect([])->customCollectionMacro());
    assertType('string', Collection::customCollectionMacro());

    assertType('string', collect([])->customCollectionMacroString());
    assertType('string', Collection::customCollectionMacroString());

    assertType('string', Str::trimMacro(''));
    assertType('string', Str::asciiAliasMacro(''));

    assertType('mixed', Cache::rememberIf(true, 'key', 60, fn () => 'value'));

    assertVariableCertainty(TrinaryLogic::createMaybe(), $foo);

    Request::macro('foo', function () {
        assertType(Request::class, $this);
    });

    Foo::macro('foo', function () {
        assertType(Foo::class, $this);
    });
}

class Foo
{
    use Macroable;
}

// Hypervel: Builder::__callStatic() binds a global macro to the builder and registers a mixin's methods, returning
// null. Builder::__call() calls a local macro unbound, and an instance mixin() reaches the query's, as a model's does.
function builderRegistration(Post $post): void
{
    Builder::macro('globalRegistration', function () {
        assertType('Hypervel\Database\Eloquent\Builder', $this);
    });

    PostBuilder::macro('postRegistration', function () {
        assertType('App\PostBuilder', $this);
    });

    assertType('null', Builder::mixin(new stdClass));
    assertType('App\PostBuilder<App\Post>', Post::query()->mixin(new stdClass));
    assertType('App\PostBuilder<App\Post>', Post::mixin(new stdClass));
    assertType('App\PostBuilder<App\Post>', $post->mixin(new stdClass));
}

class LocalMacroProvider
{
    /**
     * Register a local macro, which keeps this provider as its $this.
     *
     * @param Builder<User> $query
     */
    public function register(Builder $query): void
    {
        $query->macro('localRegistration', function (Builder $builder) {
            assertType('$this(Macros\LocalMacroProvider)', $this);
        });
    }
}

// Hypervel: @method tags type Request's macros, and MacroThrowTypeExtension adds the macros' exceptions.
function taggedMacros(Request $request, NativeValidateRequest $nativeRequest, CustomCallHandler $handler): void
{
    assertType('array', $request->validate([]));
    assertType('array', $request->validateWithBag('bag', []));
    assertType('array', RequestFacade::validate([]));
    assertType('bool', $request->hasValidSignature());
    assertType('bool', $request->hasValidRelativeSignature());

    try {
        $request->validate([]);
    } catch (ValidationException $e) {
        $instance = 'instance';
    }

    assertVariableCertainty(TrinaryLogic::createMaybe(), $instance);

    try {
        RequestFacade::validateWithBag('bag', []);
    } catch (ValidationException $e) {
        $facade = 'facade';
    }

    assertVariableCertainty(TrinaryLogic::createMaybe(), $facade);

    // A native method overriding a macro keeps its own exceptions.
    try {
        $nativeRequest->validate([]);
    } catch (ValidationException $e) {
        $native = 'native';
    }

    assertVariableCertainty(TrinaryLogic::createNo(), $native);

    // A magic handler keeps its declared exception when no macro replaces it.
    try {
        $handler->handled();
    } catch (CustomCallException $e) {
        $handled = 'handled';
    }

    assertVariableCertainty(TrinaryLogic::createMaybe(), $handled);
}

// Hypervel: Macroable calls invokable macros directly, both ways, and passes every macro its argument values.
function callableMacros(MacroHost $host): void
{
    $value = 1;

    assertType('int', $host->invokableMacro($value));
    assertType('int', MacroHost::invokableMacro($value));
    assertType('1', $value);

    $host->referenceMacro($value);
    assertType('1', $value);
}

class NativeValidateRequest extends Request
{
    /**
     * Validate the request natively instead of through the macro.
     *
     * @throws RuntimeException
     */
    public function validate(array $rules): array
    {
        throw new RuntimeException;
    }
}

/**
 * @method int handled()
 */
class CustomCallHandler
{
    /**
     * Handle a documented magic method.
     *
     * @throws CustomCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        throw new CustomCallException;
    }
}

class CustomCallException extends RuntimeException
{
}

class ModelWithMacroNamedScope extends Model
{
    /** @param Builder<self> $query */
    public function scopeGlobalCustomMacro(Builder $query): void
    {
    }
}

/**
 * A builder that redeclares the registry keeps its own macros, without the base builder's.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class IsolatedMacroBuilder extends Builder
{
    protected static array $macros = [];
}

function isolatedMacroRegistry(): void
{
    assertType('*ERROR*', IsolatedMacroBuilder::globalCustomMacro());
}
