<?php

namespace Helpers;

use App\User;
use Exception;
use Hypervel\PHPStan\ApplicationResolver;
use Hypervel\Support\Str;
use Throwable;

use function PHPStan\Testing\assertType;

/**
 * @param int|null     $value
 * @param int|(\Closure(mixed): string) $intOrClosure
 *
 * @return void
 * @throws Throwable
 */
function test(?int $value = 0, int|\Closure $intOrClosure = 0, int|\Closure $intOrClosureWithNoDocBlock = 0): void
{
    assertType('Hypervel\Foundation\Application', app());
    assertType('Hypervel\PHPStan\ApplicationResolver', app(ApplicationResolver::class));
    assertType('Hypervel\Auth\AuthManager', app('auth'));
    assertType('Hypervel\PHPStan\ApplicationResolver', resolve(ApplicationResolver::class));
    assertType('Hypervel\Auth\AuthManager', resolve('auth'));

    assertType('Hypervel\Auth\AuthManager', auth());
    // Hypervel: guards are typed from their configured drivers.
    assertType('Hypervel\Auth\SessionGuard', auth()->guard('web'));
    assertType('Hypervel\Auth\SessionGuard', auth('web'));
    assertType('App\Admin|App\User|null', auth()->user());
    assertType('bool', auth()->check());
    assertType('App\User|null', auth()->guard('web')->user());
    assertType('App\User|null', auth('web')->user());
    assertType('App\Admin|null', auth()->guard('admin')->user());
    assertType('App\Admin|null', auth('admin')->user());
    assertType('int|string|null', auth()->id());
    assertType('int|string|null', auth('web')->id());
    assertType('int|string|null', auth('admin')->id());
    assertType('Hypervel\Contracts\Auth\Authenticatable|false', auth()->loginUsingId(1));
    assertType('null', auth()->login(new User()));

    // REMOVED: now() and today() assertions; their CarbonInterface return types come from source, and date-extension-l1{1,2}.php covers the omitted extension.

    assertType('Hypervel\Http\RedirectResponse', redirect('/'));
    assertType('Hypervel\Routing\Redirector', redirect());

    assertType('Hypervel\Http\Request', request());
    assertType('mixed', request('foo'));
    // Hypervel: the source declares the input keys and values that request() returns for an array.
    assertType('array<string, mixed>', request(['foo', 'bar']));

    assertType('\'ok\'|null', rescue(function () {
        if (mt_rand(0, 1)) {
            throw new Exception();
        }

        return 'ok';
    }));

    assertType('\'failed\'|\'ok\'', rescue(function () {
        if (mt_rand(0, 1)) {
            throw new Exception();
        }

        return 'ok';
    }, 'failed'));

    assertType('0|\'ok\'', rescue(function () {
        if (mt_rand(0, 1)) {
            throw new Exception();
        }

        return 'ok';
    }, function () {
        return 0;
    }));

    assertType('0|\'ok\'', rescue(function () {
        if (mt_rand(0, 1)) {
            throw new Exception();
        }

        return 'ok';
    }, function (Throwable $e) {
        return 0;
    }));

    assertType('\'failed\'|\'ok\'', rescue(function () {
        if (mt_rand(0, 1)) {
            throw new Exception();
        }

        return 'ok';
    }, 'failed', false));

    assertType('Hypervel\Http\Response', response('foo'));
    assertType('Hypervel\Contracts\Routing\ResponseFactory', response());

    assertType('null', retry(3, function () {
    }));

    assertType('5', retry(3, function (): int {
        return 5;
    }));

    assertType('App\User|null', retry(5, function (): ?User {
        return User::first();
    }, 0, function (): bool {
        return true;
    }));

    assertType('false', retry(5, function (int $attempt): bool {
        return false;
    }, 0, function (Exception $e): bool {
        return true;
    }));

    assertType('Hypervel\Support\Stringable', str('foo'));
    assertType('mixed', str());

    // Hypervel: replacing changes the subject, so only its keys are kept.
    assertType('string', Str::replace('foo', 'bar', 'Laravel'));
    assertType('array<0|1, string>', Str::replace('foo', 'bar', ['Laravel', 'Framework']));
    assertType('array<int, string>', Str::replace('foo', 'bar', collect(['Laravel', 'Framework'])));

    assertType('App\User', tap(new User(), function (User $user): void {
        $user->name = 'Can Vural';
        $user->save();
    }));

    assertType('Hypervel\Support\HigherOrderTapProxy<App\User>', tap(new User()));
    assertType('App\User', tap(new User())->update(['name' => 'Taylor Otwell']));
    // Hypervel: validator() returns the Validator contract its factory declares, so contract methods replace valid() and addReplacers().
    assertType('Hypervel\Contracts\Validation\Validator', tap(validator([], []))->after(fn () => null));

    assertType('string', url('/path'));
    assertType('Hypervel\Contracts\Routing\UrlGenerator', url());

    assertType('Hypervel\Contracts\Validation\Factory', validator());
    assertType('Hypervel\Contracts\Validation\Validator', validator(['foo' => 'bar'], ['foo' => 'required']));
    assertType('array', validator(['foo' => 'bar'], ['foo' => 'required'])->validated());

    assertType('App\User|null', value(function (): ?User {
        return User::first();
    }));

    assertType('5', value(5));
    assertType('int|string', value($intOrClosure));
    assertType('mixed', value($intOrClosureWithNoDocBlock));
    assertType('Helpers\InvokableValue', value(new InvokableValue()));
    assertType("'strtoupper'", value('strtoupper'));
    assertType("array{Helpers\InvokableValue, '__invoke'}", value([new InvokableValue(), '__invoke']));
    assertType('array{Closure(): 1}', value([fn () => 1]));
    assertType('Hypervel\Support\Collection<int, Closure(): 1>', value(collect([fn () => 1])));

    assertType('array<string, mixed>|null', transform(User::first(), fn (User $user) => $user->getAttributes()));
    assertType('array<string, mixed>', transform(User::sole(), fn (User $user) => $user->getAttributes()));

    // falls back to default if provided
    // Hypervel: optional() is an Optional object, which is never blank, so a value that may be null shows the default.
    assertType('1', transform(optional(), fn () => 1, 'default'));
    assertType("1|'default'", transform(User::first(), fn () => 1, 'default'));
    // default as callable
    assertType('1', transform(optional(), fn () => 1, fn () => 'string'));
    assertType("1|'string'", transform(User::first(), fn () => 1, fn () => 'string'));

    // non empty values
    assertType('1', transform('filled', fn () => 1));
    assertType('1', transform(['filled'], fn () => 1));
    assertType('1', transform(new User(), fn () => 1));

    // "empty" values
    assertType('null', transform(null, fn () => 1));
    assertType('null', transform('', fn () => 1));
    assertType('null', transform([], fn () => 1));

    // Hypervel: integers are never blank, so only null remains when the value is blank.
    if (filled($value)) {
        assertType('int', $value);
    } else {
        assertType('null', $value);
    }

    if (blank($value)) {
        assertType('null', $value);
    } else {
        assertType('int', $value);
    }

    // Hypervel: a default does not decide the type of a value that is set.
    assertType('bool|string|null', env('foo'));
    assertType('bool|string|null', env('foo'));
    assertType('120|bool|string|null', env('foo', 120));
    assertType('bool|string|null', env('foo', ''));
    assertType('120|bool|string|null', env('foo', fn () => 120));
    assertType('bool|string|null', env('foo', null));
    assertType('bool|string|null', env('foo', fn () => null));

    assertType('true', literal(true));
    assertType('int<0, 10>', literal(random_int(0,10)));
    assertType("object{bar: 'bar'}&stdClass", literal(bar: "bar"));
    assertType("object{foo: 22, bar: 'bar'}&stdClass", literal(foo: 22, bar: "bar"));
    assertType("object{foo: int<0, 5>, bar: 'bar'}&stdClass", literal(foo: random_int(0,5), bar: "bar"));
    assertType("object{}&stdClass", literal(new \stdClass));
    assertType("object{}&stdClass", literal());
    assertType("object{0: 'bar', 1: 'foo'}&stdClass", literal('bar', 'foo'));
    assertType("object{0: 4, bar: 'foo'}&stdClass", literal(4, bar:'foo'));
    assertType("App\User", literal(new User()));
    assertType("array{foo: 22, bar: 'bar'}", literal(['foo' => 22, 'bar' => "bar"]));
    assertType("object{0: 5, 1: 7}&stdClass", literal(...[5,7]));
    assertType("object{foo: 22, bar: 'bar'}&stdClass", literal(...['foo' => 22, 'bar' => "bar"]));
}

/**
 * Hypervel: helpers decide by the arguments given, and an unpacked argument is the list holding the values.
 *
 * @param list<\Closure(): 1> $closures
 * @param list<mixed> $arguments
 */
function testHypervelArguments(array $closures, array $arguments): void
{
    assertType('Hypervel\Support\HigherOrderTapProxy<App\User>', tap(new User(), null));
    assertType('Hypervel\Support\Stringable', str(null));
    assertType('1', value(...$closures));
    assertType('Hypervel\Support\Collection<int, int>', collect(...[[1, 2]]));
    assertType('mixed', literal(...$arguments));
    // An unpacked list may be empty, so these may return the factory or the result.
    assertType('Hypervel\Contracts\Routing\ResponseFactory|Hypervel\Http\Response', response(...$arguments));
    assertType('mixed', str(...$arguments));
    assertType('Hypervel\Contracts\Validation\Factory|Hypervel\Contracts\Validation\Validator', validator(...$arguments));
    assertType('Hypervel\Contracts\View\Factory|Hypervel\Contracts\View\View', view(...$arguments));
}

/**
 * Hypervel: literal() receives integer keys by position and string keys by name.
 *
 * @param array{0: 'a', 1?: 'b'} $maybeTwo
 * @param array{5?: 'a', 9: 'b'} $maybeFirst
 * @param array{0?: 'a', 1?: 'b', 2?: 'c', 3?: 'd', 4?: 'e', 5?: 'f', 6?: 'g', 7?: 'h', 8?: 'i', 9?: 'j', 10?: 'k'} $maybeAny
 * @param array{x: 'a', y?: 'b'} $maybeNamed
 * @param array{x: 'a', 5?: 'b'} $namedThenMaybe
 * @param array{x: 'a', ...} $unsealed
 */
function testLiteralUnpacking(array $maybeTwo, array $maybeFirst, array $maybeAny, array $maybeNamed, array $namedThenMaybe, array $unsealed): void
{
    assertType("object{0: 'a', 1: 'b'}&stdClass", literal(...[5 => 'a', 9 => 'b']));
    assertType("'a'", literal(...[5 => 'a']));
    assertType("object{0: 'a', x: 'b'}&stdClass", literal(...[5 => 'a', 'x' => 'b']));
    assertType('mixed', literal(...$unsealed));

    // An optional argument moves the positions after it, and any single positional argument may be returned.
    assertType("'a'|(object{0: 'a', 1?: 'b'}&stdClass)", literal(...$maybeTwo));
    assertType("'b'|(object{}&stdClass)", literal(...$maybeFirst));
    assertType("'a'|'b'|'c'|'d'|'e'|'f'|'g'|'h'|'i'|'j'|'k'|(object{}&stdClass)", literal(...$maybeAny));
    assertType("object{x: 'a', y?: 'b'}&stdClass", literal(...$maybeNamed));

    // PHP throws when a positional argument follows a named one.
    assertType('*NEVER*', literal(...['x' => 'a', 5 => 'b']));
    assertType("object{x: 'a'}&stdClass", literal(...$namedThenMaybe));
}

/**
 * @param array{loo:'loo'}|array{foo:'foo',bar:'baa'} $parameter
 */
function testUnion($parameter): int {
    assertType("(object{foo: 'foo', bar: 'baa'}&stdClass)|(object{loo: 'loo'}&stdClass)", literal(...$parameter));

    return 0;
};

/**
 * @param callable(): int $intCallable
 */
function testCallable(callable $intCallable): void
{
    assertType('(callable(): int)|int', value($intCallable));
}

class InvokableValue
{
    public function __invoke(): int
    {
        return 1;
    }
}
