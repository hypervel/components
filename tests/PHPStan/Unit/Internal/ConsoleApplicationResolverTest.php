<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Internal;

use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use Hypervel\Tests\PHPStan\Fixtures\Console\LookupCommand;
use Hypervel\Tests\PHPStan\Fixtures\Console\WithArgumentCommand;
use Hypervel\Tests\PHPStan\Fixtures\Console\WithoutArgumentCommand;
use Hypervel\Tests\PHPStan\TestCase;
use Mockery as m;
use PHPStan\Testing\PHPStanTestCase;

use function array_values;

class ConsoleApplicationResolverTest extends TestCase
{
    public function testFindCommandsListsEachRegisteredCommandOnce(): void
    {
        $with = new WithArgumentCommand;
        $without = new WithoutArgumentCommand;

        $container = new Container;
        $container->instance(Kernel::class, m::mock(Kernel::class, ['all' => [
            'lookup:with' => $with,
            'lookup:with-alias' => $with,
            // A command loader may list a command only under a key other than its own name.
            'lookup:without-alias' => $without,
        ]]));

        $resolver = new ConsoleApplicationResolver;
        $resolver->setContainer($container);

        $commands = $resolver->findCommands(PHPStanTestCase::createReflectionProvider()->getClass(LookupCommand::class));

        $this->assertSame([$with, $without], array_values($commands));
    }
}
