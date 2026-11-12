<?php

namespace ConfigCollectionRule;

use Hypervel\Config\Repository;
use Hypervel\Support\Facades\Config;

class Foo
{
    public function bar(): void
    {
        Config::collection('test.foo');
        Config::collection('test.bar');
        Config::collection('test.bar', 5);
    }

    public function receivers(Repository $config): void
    {
        $config->collection('test.foo');
        config()->collection('test.foo');
    }

    public function presence(): void
    {
        // An absent key gives the default, which array() rejects
        Config::collection('test.missing');
        Config::collection('test.missing', []);

        // Framework code adds log channels, so an undeclared one is unknown
        Config::collection('logging.channels.undeclared');
    }
}
