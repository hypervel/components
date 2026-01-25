<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router\Stub;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Contracts\Router\UrlRoutable;

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
