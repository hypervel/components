<?php

namespace UntypedConfigReads;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Config;

class Reads
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @param array{string} $arguments */
    public function read(ConfigRepository $injected, ?Repository $optional, Repository|Collection $either, string|array $key, string $dynamic, array $arguments): void
    {
        config(default: null, key: 'typed.name');
        Config::get('typed.port');
        config()->get('typed.either');
        $injected->get($dynamic);
        $this->config->get('typed.name');
        $optional?->getMany(['typed.name']);
        app('config')->get(...$arguments);
        (new Repository)->get(['typed.name']);
        Config::getMany(['typed.port']);

        config();
        config(null);
        config(['typed.name' => 'changed']);
        config($key);
        config(...$arguments);
        Config::get(...);
        $injected->get(...);
        $either->get('typed.name');
        config()->nullableString('typed.name');

        // @phpstan-ignore hypervel.untypedConfigRead (The inspected key can contain any configured value.)
        $injected->get($dynamic);
    }
}

class CustomRepository extends Repository
{
    public function value(): mixed
    {
        return $this->get('typed.name');
    }
}
