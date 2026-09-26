<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Concerns\PromptsForMissingInput;
use Hypervel\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class PromptsForMissingInputTest extends TestCase
{
    public function testDidReceiveOptionsReturnsFalseWhenAllOptionsMatchTheirDefaults(): void
    {
        $stub = $this->makeStub();

        $input = new ArrayInput([], $stub->getDefinition());

        $this->assertFalse($stub->didReceiveOptions($input));
    }

    public function testDidReceiveOptionsReturnsTrueWhenAnOptionDiffersFromItsDefault(): void
    {
        $stub = $this->makeStub();

        $input = new ArrayInput(['--force' => true], $stub->getDefinition());

        $this->assertTrue($stub->didReceiveOptions($input));
    }

    /**
     * Create a command input stub.
     */
    protected function makeStub(): object
    {
        return new class {
            use PromptsForMissingInput {
                didReceiveOptions as public;
            }

            /**
             * Get the command input definition.
             */
            public function getDefinition(): InputDefinition
            {
                return new InputDefinition([
                    new InputOption('force', null, InputOption::VALUE_NONE),
                    new InputOption('name', null, InputOption::VALUE_OPTIONAL, '', 'default-name'),
                ]);
            }
        };
    }
}
