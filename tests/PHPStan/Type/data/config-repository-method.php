<?php

namespace ConfigRepositoryMethod;

use Hypervel\Config\Repository;
use Hypervel\Support\Facades\Config;
use function PHPStan\Testing\assertType;

// Unlike upstream, an absent key returns its default and a present key ignores it, and only the
// application's repository has the application's values: config() or a resolution of it from the
// application container.
function test(Repository $config): void
{
    assertType('null', config()->get('foo'));
    assertType('array{guard: \'web\', passwords: \'users\'}', app('config')->get('auth.defaults'));

    assertType('array{1, 2, 3}', config()->get('test.bar'));
    assertType("'bar'", app(Repository::class)->get('test.foo'));
    assertType("'bar'", config()->get('test.foo', 5));

    // A repository received or built elsewhere may hold other values.
    assertType('mixed', $config->get('test.foo'));
    assertType('mixed', (new Repository(['test' => ['foo' => 123]]))->get('test.foo'));
    assertType('string', $config->string('test.bar'));
}

class CustomValue
{
}

class CustomRepository extends Repository
{
    public function get(array|string $key, mixed $default = null): CustomValue
    {
        return new CustomValue;
    }
}

class CustomArrayRepository extends Repository
{
    public function array(string $key, mixed $default = null): array
    {
        return [];
    }
}

class CustomConfig extends Config
{
    public static function get(array|string $key, mixed $default = null): CustomValue
    {
        return new CustomValue;
    }
}

// A custom implementation keeps its declared type, and so do the inherited getters that
// read through it. Facade calls forward to the bound repository, not the facade's methods.
function customImplementations(CustomRepository $config, CustomArrayRepository $arrayConfig): void
{
    assertType('ConfigRepositoryMethod\CustomValue', $config->get('test.foo'));
    assertType('string', $config->string('test.foo'));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $arrayConfig->collection('test.bar'));
    assertType('string', $arrayConfig->string('test.foo'));
    assertType('ConfigRepositoryMethod\CustomValue', CustomConfig::get('test.foo'));
    assertType("'bar'", CustomConfig::string('test.foo'));
}
