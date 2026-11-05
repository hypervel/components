<?php

declare(strict_types=1);

namespace ConfigTypeRule;

use Hypervel\Config\Repository;
use Hypervel\Support\Facades\Config;

class Foo
{
    public function getters(Repository $config): void
    {
        Config::integer('test.foo');
        Config::string('test.foo');
        $config->array('test.foo');
        config()->float('auth.defaults.guard');
    }

    public function uncertainValues(): void
    {
        // An env() value may be a string
        Config::string('test.default');

        // Framework code adds log channels, so an undeclared one is unknown
        Config::string('logging.channels.undeclared');
    }

    public function absentKeys(): void
    {
        Config::boolean('test.missing');
        Config::boolean('test.missing', false);
    }

    /**
     * @param array{string} $arguments
     */
    public function argumentForms(array $arguments): void
    {
        Config::integer(default: 1, key: 'test.foo');

        // Unpacked arguments are not resolved
        Config::integer(...$arguments);
    }

    public function customGetter(LenientRepository $config): void
    {
        // A custom getter keeps its own semantics
        $config->integer('test.foo');
    }
}

class LenientRepository extends Repository
{
    public function integer(string $key, mixed $default = null): int
    {
        return (int) $this->get($key, $default);
    }
}
