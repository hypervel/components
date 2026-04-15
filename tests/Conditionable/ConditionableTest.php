<?php

declare(strict_types=1);

namespace Hypervel\Tests\Conditionable;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\HigherOrderWhenProxy;
use Hypervel\Tests\TestCase;

class ConditionableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();
    }

    public function testWhen()
    {
        $this->assertInstanceOf(HigherOrderWhenProxy::class, TestConditionableModel::query()->when(true));
        $this->assertInstanceOf(HigherOrderWhenProxy::class, TestConditionableModel::query()->when(false));
        $this->assertInstanceOf(HigherOrderWhenProxy::class, TestConditionableModel::query()->when());
        $this->assertInstanceOf(Builder::class, TestConditionableModel::query()->when(false, null));
        $this->assertInstanceOf(Builder::class, TestConditionableModel::query()->when(true, function () {
        }));
    }

    public function testUnless()
    {
        $this->assertInstanceOf(HigherOrderWhenProxy::class, TestConditionableModel::query()->unless(true));
        $this->assertInstanceOf(HigherOrderWhenProxy::class, TestConditionableModel::query()->unless(false));
        $this->assertInstanceOf(HigherOrderWhenProxy::class, TestConditionableModel::query()->unless());
        $this->assertInstanceOf(Builder::class, TestConditionableModel::query()->unless(true, null));
        $this->assertInstanceOf(Builder::class, TestConditionableModel::query()->unless(false, function () {
        }));
    }
}

class TestConditionableModel extends Model
{
}
