<?php

declare(strict_types=1);

use Hypervel\Config\Repository;

use function PHPStan\Testing\assertType;

assertType('Hypervel\Foundation\Application', app());
assertType('mixed', app('foo'));
assertType('Hypervel\Config\Repository', app(Repository::class));

assertType('Hypervel\Contracts\Auth\Factory', auth());
assertType('Hypervel\Contracts\Auth\Guard', auth('foo'));

assertType('Hypervel\Cache\CacheManager', cache());
assertType('bool', cache(['foo' => 'bar'], 42));
assertType('mixed', cache('foo', 42));

assertType('Hypervel\Config\Repository', config());
assertType('null', config(['foo' => 'bar']));
assertType('mixed', config('foo'));

assertType('Hypervel\Log\Context\Repository', context());
assertType('Hypervel\Log\Context\Repository', context(['foo' => 'bar']));
assertType('mixed', context('foo'));

assertType('Hypervel\Cookie\CookieJar', cookie());
assertType('Symfony\Component\HttpFoundation\Cookie', cookie('foo'));

assertType('Hypervel\Foundation\Bus\PendingDispatch', dispatch('foo'));
assertType('Hypervel\Foundation\Bus\PendingClosureDispatch', dispatch(fn () => 1));

assertType('Psr\Log\LoggerInterface', logger());
assertType('null', logger('foo'));

assertType('Hypervel\Log\LogManager', logs());
assertType('Psr\Log\LoggerInterface', logs('foo'));

assertType('123|null', rescue(fn () => 123));
assertType('123|345', rescue(fn () => 123, 345));
assertType('123|345', rescue(fn () => 123, fn () => 345));

assertType('Hypervel\Routing\Redirector', redirect());
assertType('Hypervel\Http\RedirectResponse', redirect('foo'));

assertType('mixed', resolve('foo'));
assertType('Hypervel\Config\Repository', resolve(Repository::class));

assertType('Hypervel\Http\Request', request());
assertType('mixed', request('foo'));
assertType('array<string, mixed>', request(['foo', 'bar']));

assertType('Hypervel\Contracts\Routing\ResponseFactory', response());
assertType('Hypervel\Http\Response', response('foo'));
assertType('Hypervel\Http\Response', response(null));
assertType('Hypervel\Http\Response', response(status: 204));

assertType('Hypervel\Session\SessionManager', session());
assertType('mixed', session('foo'));
assertType('null', session(['foo' => 'bar']));

assertType('Hypervel\Translation\Translator', trans());
assertType('array|string', trans('foo'));

assertType('Hypervel\Contracts\Validation\Factory', validator());
assertType('Hypervel\Contracts\Validation\Validator', validator([]));
assertType('Hypervel\Contracts\Validation\Validator', validator(null));
assertType('Hypervel\Contracts\Validation\Validator', validator(rules: ['name' => 'required']));

assertType('Hypervel\Contracts\View\Factory', view());
assertType('Hypervel\Contracts\View\View', view('foo'));

assertType('Hypervel\Contracts\Routing\UrlGenerator', url());
assertType('string', url('foo'));

/**
 * Check factory helpers with unpacked arguments.
 *
 * @param array{0?: string, 1?: int, 2?: array<string, string>} $responseArguments
 * @param array{0?: int, 1?: array<string, string>} $responseOptions
 * @param list<array<string, mixed>> $validationArguments
 */
function testFactoryHelperArguments(array $responseArguments, array $responseOptions, array $validationArguments): void
{
    assertType('Hypervel\Http\Response', response('body', ...$responseOptions));
    assertType('Hypervel\Contracts\Routing\ResponseFactory|Hypervel\Http\Response', response(...$responseArguments));
    assertType('Hypervel\Contracts\Validation\Validator', validator([], ...$validationArguments));
    assertType('Hypervel\Contracts\Validation\Factory|Hypervel\Contracts\Validation\Validator', validator(...$validationArguments));
}
