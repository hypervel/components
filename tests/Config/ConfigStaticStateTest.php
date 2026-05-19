<?php

declare(strict_types=1);

namespace Hypervel\Tests\Config;

use Hypervel\Config\Repository;
use Hypervel\Tests\TestCase;

class ConfigStaticStateTest extends TestCase
{
    public function testFlushStateClearsMacros()
    {
        Repository::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(Repository::hasMacro('testingStaticStateProbe'));

        Repository::flushState();

        $this->assertFalse(Repository::hasMacro('testingStaticStateProbe'));
    }
}
