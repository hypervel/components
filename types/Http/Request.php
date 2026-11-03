<?php

declare(strict_types=1);

use Hypervel\Http\Request;

use function PHPStan\Testing\assertType;

enum TestEnum: string
{
    case Foo = 'foo';
}

$request = Request::create('/', 'GET', [
    'key' => 'test',
]);

assertType('TestEnum|null', $request->enum('key', TestEnum::class));
assertType('TestEnum|TestEnum::Foo', $request->enum('key', TestEnum::class, TestEnum::Foo));

assertType('int', $request->integer('key'));
assertType('int', $request->integer('key', 5, true));
assertType('int', $request->integer('key', fn () => 5, true));
assertType('int|null', $request->integer('key', null, true));
assertType('float', $request->float('key'));
assertType('float|null', $request->float('key', null, true));
assertType('bool', $request->boolean('key'));
assertType('bool|null', $request->boolean('key', null, true));

assertType('Hypervel\Routing\Route|null', $request->route());
assertType('object|string|null', $request->route('key'));

assertType('Symfony\Component\HttpFoundation\InputBag', $request->json());
assertType('mixed', $request->json('key'));
