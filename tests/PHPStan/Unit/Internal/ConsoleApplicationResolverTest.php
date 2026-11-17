<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Internal;

use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\PHPStan\Internal\ConsoleApplicationResolver;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Tests\PHPStan\Fixtures\Console\LookupCommand;
use Hypervel\Tests\PHPStan\Fixtures\Console\WithArgumentCommand;
use Hypervel\Tests\PHPStan\Fixtures\Console\WithoutArgumentCommand;
use Hypervel\Tests\PHPStan\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPStan\Testing\PHPStanTestCase;

use function array_values;

class ConsoleApplicationResolverTest extends TestCase
{
    use VerifiesDoubles;

    public function testFindCommandsListsEachRegisteredCommandOnce(): void
    {
        $with = new WithArgumentCommand;
        $without = new WithoutArgumentCommand;

        $kernel = Double::for(Kernel::class)->strict();
        $kernel->allows('all')->returns([
            'lookup:with' => $with,
            'lookup:with-alias' => $with,
            // A command loader may list a command only under a key other than its own name.
            'lookup:without-alias' => $without,
        ]);
        $container = new Container;
        $container->instance(Kernel::class, $kernel);

        $reflectionProvider = PHPStanTestCase::createReflectionProvider();
        $resolver = new ConsoleApplicationResolver($reflectionProvider, Double::for(RuntimeInputs::class)->strict());
        $resolver->setContainer($container);

        $commands = $resolver->findCommands($reflectionProvider->getClass(LookupCommand::class));

        $this->assertSame([$with, $without], array_values($commands));
    }
}
