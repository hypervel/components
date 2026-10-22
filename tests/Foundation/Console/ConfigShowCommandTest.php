<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Foundation\Console\ConfigShowCommand;
use Hypervel\Support\Facades\Artisan;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

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

    public function testDisplayConfig(): void
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

    public function testDisplayNestedConfigItems(): void
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

    public function testDisplaySingleValue(): void
    {
        config()->set('foo', 'bar');

        $this->artisan(ConfigShowCommand::class, ['config' => 'foo'])
            ->assertSuccessful()
            ->expectsOutput('  foo .................................................... bar  ');
    }

    public function testDisplayErrorIfConfigDoesNotExist(): void
    {
        $this->artisan(ConfigShowCommand::class, ['config' => 'invalid'])
            ->assertFailed();
    }

    #[DataProvider('jsonValues')]
    public function testJsonPreservesTheSelectedValue(mixed $value): void
    {
        config()->set('test.selected', $value);

        $this->assertSame(0, Artisan::call(ConfigShowCommand::class, [
            'config' => 'test.selected',
            '--json' => true,
        ]));
        $this->assertSame($value, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Provide configuration values whose JSON types must be preserved.
     */
    public static function jsonValues(): array
    {
        return [
            'section' => [['nested' => ['enabled' => false, 'count' => 3, 'ratio' => 1.5, 'optional' => null]]],
            'string with console markup' => ['<info>value</info>'],
            'boolean' => [false],
            'null' => [null],
            'empty array' => [[]],
        ];
    }

    public function testJsonFailsForMissingConfiguration(): void
    {
        $this->artisan(ConfigShowCommand::class, ['config' => 'invalid', '--json' => true])
            ->assertFailed();
    }

    public function testJsonReportsEncodingFailures(): void
    {
        config()->set('test.value', "\xB1");

        $this->expectException(JsonException::class);

        Artisan::call(ConfigShowCommand::class, ['config' => 'test.value', '--json' => true]);
    }
}
