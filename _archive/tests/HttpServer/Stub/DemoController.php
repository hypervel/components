<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer\Stub;

class DemoController
{
    public function __construct()
    {
    }

    public function __invoke()
    {
        return 'Action for an invokable controller.';
    }

    public function __return(...$args)
    {
        return $args;
    }

    public function index(int $id, string $name = 'Hyperf', array $params = [])
    {
        return $this->__return($id, $name, $params);
    }

    public function demo()
    {
        return 'Hello World.';
    }
}
