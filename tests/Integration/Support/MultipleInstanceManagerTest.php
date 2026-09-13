<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Support;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Support\Fixtures\MultipleInstanceManager;
use Mockery as m;
use RuntimeException;

class MultipleInstanceManagerTest extends TestCase
{
    public function testConfigurableInstancesCanBeResolved(): void
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
        $this->assertSame(spl_object_id($fooInstance), spl_object_id($duplicateFooInstance));
        $this->assertSame(spl_object_id($barInstance), spl_object_id($duplicateBarInstance));
        $this->assertSame(spl_object_id($mysqlInstance), spl_object_id($duplicateMysqlInstance));
    }

    public function testSetApplicationRefreshesConfigWithoutRebuildingResolvedInstances(): void
    {
        $manager = new MultipleInstanceManager($this->app);
        $resolved = $manager->instance('foo');
        $config = new Repository([
            'instances' => [
                'configured' => [
                    'driver' => 'foo',
                    'source' => 'replacement',
                ],
            ],
        ]);
        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('config')->andReturn($config);

        $this->assertSame($manager, $manager->setApplication($application));
        $this->assertSame($resolved, $manager->instance('foo'));
        $this->assertSame('replacement', $manager->instance('configured')->config['source']);
    }

    public function testUnresolvableInstancesThrowErrors(): void
    {
        $this->expectException(RuntimeException::class);

        $manager = new MultipleInstanceManager($this->app);

        $manager->instance('missing');
    }

    public function testCustomDriverClosureBoundObjectIsMultipleInstanceManager(): void
    {
        $manager = new MultipleInstanceManager($this->app);
        $manager->extend('custom', fn (): object => $this);
        $this->assertSame($manager, $manager->instance('custom'));
    }
}
