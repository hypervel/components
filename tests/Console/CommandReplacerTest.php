<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\CommandReplacer;
use Hypervel\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * @internal
 * @coversNothing
 */
class CommandReplacerTest extends TestCase
{
    public function testUnmappedCommandIsReturnedUnchanged()
    {
        $command = new Command('my:custom-command');

        $result = CommandReplacer::replace($command);

        $this->assertSame($command, $result);
        $this->assertSame('my:custom-command', $result->getName());
    }

    public function testCommandMappedToNullReturnsNull()
    {
        $command = new Command('info');

        $result = CommandReplacer::replace($command);

        $this->assertNull($result);
    }

    public function testCommandMappedToStringIsRenamed()
    {
        $command = new Command('gen:command');

        $result = CommandReplacer::replace($command);

        $this->assertSame($command, $result);
        $this->assertSame('make:command', $result->getName());
        $this->assertContains('gen:command', $result->getAliases());
    }

    public function testCommandMappedToStringWithoutAlias()
    {
        $command = new Command('gen:command');

        $result = CommandReplacer::replace($command, remainAlias: false);

        $this->assertSame('make:command', $result->getName());
        $this->assertEmpty($result->getAliases());
    }

    public function testCommandMappedToArrayWithNameAndDescription()
    {
        $command = new Command('start');

        $result = CommandReplacer::replace($command);

        $this->assertSame($command, $result);
        $this->assertSame('serve', $result->getName());
        $this->assertSame('Start Hypervel servers', $result->getDescription());
        $this->assertContains('start', $result->getAliases());
    }

    public function testCommandMappedToArrayWithNameAndDescriptionWithoutAlias()
    {
        $command = new Command('describe:routes');

        $result = CommandReplacer::replace($command, remainAlias: false);

        $this->assertSame('route:list', $result->getName());
        $this->assertSame('List all registered routes', $result->getDescription());
        $this->assertEmpty($result->getAliases());
    }
}
