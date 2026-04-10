<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Fixtures;

use Hypervel\Di\Aop\ProxyTrait;

class ProxyTraitObject
{
    use ProxyTrait;
    use ProxyTraitOnTrait {
        ProxyTraitOnTrait::get as getOnTrait;
        ProxyTraitOnTrait::get2 as get2OnTrait;
        ProxyTraitOnTrait::get3 as get3OnTrait;
        ProxyTraitOnTrait::get4 as get4OnTrait;
        ProxyTraitOnTrait::incr as incrOnTrait;
        ProxyTraitOnTrait::getName as getNameOnTrait;
        ProxyTraitOnTrait::getName2 as getName2OnTrait;
    }

    public function __construct(public string $name = 'Hypervel')
    {
    }

    public function get(?int $id, string $str = '')
    {
        return ['order' => ['id', 'str'], 'keys' => compact('id', 'str'), 'variadic' => ''];
    }

    public function get2(?int $id = 1, string $str = '')
    {
        return ['order' => ['id', 'str'], 'keys' => compact('id', 'str'), 'variadic' => ''];
    }

    public function get3(?int $id = 1, string $str = '', float $num = 1.0)
    {
        return ['order' => ['id', 'str', 'num'], 'keys' => compact('id', 'str', 'num'), 'variadic' => ''];
    }

    public function get4(?int $id = 1, string ...$variadic)
    {
        return ['order' => ['id', 'variadic'], 'keys' => compact('id', 'variadic'), 'variadic' => 'variadic'];
    }

    public function incr()
    {
        return self::__proxyCall(ProxyTraitObject::class, __FUNCTION__, ['keys' => []], function () {
            return 1;
        });
    }

    public function getName()
    {
        return self::__proxyCall(ProxyTraitObject::class, __FUNCTION__, ['keys' => []], function () {
            return 'HypervelCloud';
        });
    }

    public function getName2()
    {
        return self::__proxyCall(ProxyTraitObject::class, __FUNCTION__, ['keys' => []], function () {
            return 'HypervelCloud';
        });
    }

    public function getParams(?int $id = 1, string ...$variadic)
    {
        return self::__proxyCall(ProxyTraitObject::class, __FUNCTION__, ['order' => ['id', 'variadic'], 'keys' => compact(['id', 'variadic']), 'variadic' => 'variadic'], function (?int $id = 1, string ...$variadic) {
            return compact('id', 'variadic') + ['func_get_args' => func_get_args()];
        });
    }

    public function getParams2(?int $id = 1, string ...$variadic)
    {
        return self::__proxyCall(ProxyTraitObject::class, __FUNCTION__, ['order' => ['id', 'variadic'], 'keys' => compact(['id', 'variadic']), 'variadic' => 'variadic'], function (?int $id = 1, string ...$variadic) {
            return compact('id', 'variadic') + ['func_get_args' => func_get_args()];
        });
    }
}
