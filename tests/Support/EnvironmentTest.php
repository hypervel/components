<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BadMethodCallException;
use Hypervel\Support\Environment;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class EnvironmentTest extends TestCase
{
    public function testGetReturnsEnvironment()
    {
        $env = new Environment('production');

        $this->assertSame('production', $env->get());
    }

    public function testSetChangesEnvironment()
    {
        $env = new Environment('local');
        $env->set('staging');

        $this->assertSame('staging', $env->get());
    }

    public function testSetReturnsSelf()
    {
        $env = new Environment('local');

        $this->assertSame($env, $env->set('production'));
    }

    public function testIsMatchesSingleEnvironment()
    {
        $env = new Environment('production');

        $this->assertTrue($env->is('production'));
        $this->assertFalse($env->is('local'));
    }

    public function testIsMatchesMultipleEnvironments()
    {
        $env = new Environment('staging');

        $this->assertTrue($env->is('local', 'staging'));
        $this->assertFalse($env->is('production', 'testing'));
    }

    public function testIsMatchesArrayOfEnvironments()
    {
        $env = new Environment('testing');

        $this->assertTrue($env->is(['testing', 'local']));
        $this->assertFalse($env->is(['production']));
    }

    public function testIsMatchesWildcardPattern()
    {
        $env = new Environment('production');

        $this->assertTrue($env->is('prod*'));
        $this->assertFalse($env->is('dev*'));
    }

    public function testIsDebugReturnsBooleanValue()
    {
        $env = new Environment('local', true);
        $this->assertTrue($env->isDebug());

        $env = new Environment('production', false);
        $this->assertFalse($env->isDebug());
    }

    public function testSetDebugChangesDebugState()
    {
        $env = new Environment('local', false);
        $env->setDebug(true);

        $this->assertTrue($env->isDebug());
    }

    public function testSetDebugReturnsSelf()
    {
        $env = new Environment('local');

        $this->assertSame($env, $env->setDebug(true));
    }

    public function testMagicCallIsLocal()
    {
        $env = new Environment('local');

        $this->assertTrue($env->isLocal());
        $this->assertFalse($env->isProduction());
    }

    public function testMagicCallIsTesting()
    {
        $env = new Environment('testing');

        $this->assertTrue($env->isTesting());
        $this->assertFalse($env->isLocal());
    }

    public function testMagicCallIsProduction()
    {
        $env = new Environment('production');

        $this->assertTrue($env->isProduction());
        $this->assertFalse($env->isTesting());
    }

    public function testMagicCallThrowsForNonIsMethods()
    {
        $this->expectException(BadMethodCallException::class);

        $env = new Environment('local');
        $env->fooBar();
    }
}
