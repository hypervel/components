<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Foundation\Console\ConfigShowCommand;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ConfigShowCommandTest extends \Hypervel\Testbench\TestCase
{
    protected string|false $previousColumns = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousColumns = getenv('COLUMNS');
        putenv('COLUMNS=64');
    }

    protected function tearDown(): void
    {
        if ($this->previousColumns === false) {
            putenv('COLUMNS');
        } else {
            putenv("COLUMNS={$this->previousColumns}");
        }

        parent::tearDown();
    }

    public function testDisplayConfig()
    {
        config()->set('test', [
            'string' => 'Test',
            'int' => 1,
            'float' => 1.2,
            'boolean' => true,
            'null' => null,
            'array' => [
                ConfigShowCommand::class,
            ],
            'empty_array' => [],
            'assoc_array' => ['foo' => 'bar'],
            'class' => new stdClass,
        ]);

        $this->artisan(ConfigShowCommand::class, ['config' => 'test'])
            ->assertSuccessful()
            ->expectsOutput('  test .......................................................  ')
            ->expectsOutput('  string ................................................ Test  ')
            ->expectsOutput('  int ...................................................... 1  ')
            ->expectsOutput('  float .................................................. 1.2  ')
            ->expectsOutput('  boolean ............................................... true  ')
            ->expectsOutput('  null .................................................. null  ')
            ->expectsOutput('  array ⇁ 0 .... Hypervel\Foundation\Console\ConfigShowCommand  ')
            ->expectsOutput('  empty_array ............................................. []  ')
            ->expectsOutput('  assoc_array ⇁ foo ...................................... bar  ')
            ->expectsOutput('  class ............................................. stdClass  ');
    }

    public function testDisplayNestedConfigItems()
    {
        config()->set('test', [
            'nested' => [
                'foo' => 'bar',
            ],
        ]);

        $this->artisan(ConfigShowCommand::class, ['config' => 'test.nested'])
            ->assertSuccessful()
            ->expectsOutput('  test.nested ................................................  ')
            ->expectsOutput('  foo .................................................... bar  ');
    }

    public function testDisplaySingleValue()
    {
        config()->set('foo', 'bar');

        $this->artisan(ConfigShowCommand::class, ['config' => 'foo'])
            ->assertSuccessful()
            ->expectsOutput('  foo .................................................... bar  ');
    }

    public function testDisplayErrorIfConfigDoesNotExist()
    {
        $this->artisan(ConfigShowCommand::class, ['config' => 'invalid'])
            ->assertFailed();
    }
}
