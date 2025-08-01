<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

class ModelStubWithTrait extends ModelStub
{
    use FooBarTrait;
}
