<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router\Stub;

use Hyperf\Database\Model\Model;
use Hypervel\Router\Contracts\UrlRoutable;

class UrlRoutableStub implements UrlRoutable
{
    public function getRouteKey()
    {
        return '1';
    }

    public function getRouteKeyName()
    {
        return 'id';
    }

    public function resolveRouteBinding($value)
    {
        return new Model();
    }
}
