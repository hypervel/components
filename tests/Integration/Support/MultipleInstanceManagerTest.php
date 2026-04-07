<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support;

use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Support\Fixtures\MultipleInstanceManager;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class MultipleInstanceManagerTest extends TestCase
{
    public function testConfigurableInstancesCanBeResolved()
    {
        $manager = new MultipleInstanceManager($this->app);

        $fooInstance = $manager->instance('foo');
        $this->assertSame('option-value', $fooInstance->config['foo-option']);

        $barInstance = $manager->instance('bar');
        $this->assertSame('option-value', $barInstance->config['bar-option']);

        $mysqlInstance = $manager->instance('mysql_database-connection');
        $this->assertSame('option-value', $mysqlInstance->config['mysql_database-connection-option']);

        $duplicateFooInstance = $manager->instance('foo');
        $duplicateBarInstance = $manager->instance('bar');
        $duplicateMysqlInstance = $manager->instance('mysql_database-connection');
        $this->assertEquals(spl_object_hash($fooInstance), spl_object_hash($duplicateFooInstance));
        $this->assertEquals(spl_object_hash($barInstance), spl_object_hash($duplicateBarInstance));
        $this->assertEquals(spl_object_hash($mysqlInstance), spl_object_hash($duplicateMysqlInstance));
    }

    public function testUnresolvableInstancesThrowErrors()
    {
        $this->expectException(RuntimeException::class);

        $manager = new MultipleInstanceManager($this->app);

        $instance = $manager->instance('missing');
    }

    public function testCustomDriverClosureBoundObjectIsMultipleInstanceManager()
    {
        $manager = new MultipleInstanceManager($this->app);
        $manager->extend('custom', fn () => $this);
        $this->assertSame($manager, $manager->instance('custom'));
    }
}
