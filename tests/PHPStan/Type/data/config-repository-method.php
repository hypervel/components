<?php

namespace ConfigRepositoryMethod;

use Hypervel\Config\Repository;
use Hypervel\Support\Facades\Config;
use function PHPStan\Testing\assertType;

// Unlike upstream, an absent key returns its default and a present key ignores it.
function test(Repository $config): void
{
    assertType('null', $config->get('foo'));
    assertType('array{guard: \'web\', passwords: \'users\'}', $config->get('auth.defaults'));

    assertType('array{1, 2, 3}', $config->get('test.bar'));
    assertType("'bar'", $config->get('test.foo'));
    assertType("'bar'", $config->get('test.foo', 5));
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
    assertType("'bar'", $arrayConfig->string('test.foo'));
    assertType('ConfigRepositoryMethod\CustomValue', CustomConfig::get('test.foo'));
    assertType("'bar'", CustomConfig::string('test.foo'));
}
