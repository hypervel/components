<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\Define;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

#[Define('env', 'classConfig')]
class AttributeEnvironmentSetupTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        static::usesTestingFeature(new Define('env', 'globalConfig'));

        parent::setUp();
    }

    #[Test]
    public function itLoadsClassConfigHelper(): void
    {
        $this->assertSame('testbench', config('testbench.class'));
    }

    #[Test]
    #[Define('env', 'firstConfig')]
    public function itLoadsFirstConfigHelper(): void
    {
        $this->assertSame('testbench', config('database.default'));
        $this->assertSame('testbench', config('testbench.global'));
        $this->assertSame('testbench', config('testbench.one'));
        $this->assertNull(config('testbench.two'));
    }

    #[Test]
    #[DefineEnvironment('secondConfig')]
    public function itLoadsSecondConfigHelper(): void
    {
        $this->assertSame('testbench', config('database.default'));
        $this->assertSame('testbench', config('testbench.global'));
        $this->assertNull(config('testbench.one'));
        $this->assertSame('testbench', config('testbench.two'));
    }

    #[Test]
    #[DefineEnvironment('firstConfig')]
    #[DefineEnvironment('secondConfig')]
    public function itLoadsBothConfigHelper(): void
    {
        $this->assertSame('testbench', config('database.default'));
        $this->assertSame('testbench', config('testbench.global'));
        $this->assertSame('testbench', config('testbench.one'));
        $this->assertSame('testbench', config('testbench.two'));
    }

    #[Test]
    #[Define('foo', 'firstConfig')]
    public function itDoesntLoadInvalidEnvironmentConfig(): void
    {
        $this->assertSame('testbench', config('database.default'));
        $this->assertSame('testbench', config('testbench.global'));
        $this->assertNull(config('testbench.one'));
        $this->assertNull(config('testbench.two'));
    }

    /**
     * Define environment setup.
     */
    protected function classConfig(ApplicationContract $app): void
    {
        $app['config']->set('testbench.class', 'testbench');
    }

    /**
     * Define environment setup.
     */
    protected function globalConfig(ApplicationContract $app): void
    {
        $app['config']->set('testbench.global', 'testbench');
    }

    /**
     * Define environment setup.
     */
    protected function firstConfig(ApplicationContract $app): void
    {
        $app['config']->set('testbench.one', 'testbench');
    }

    /**
     * Define environment setup.
     */
    protected function secondConfig(ApplicationContract $app): void
    {
        $app['config']->set('testbench.two', 'testbench');
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}
