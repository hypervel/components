<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Generator;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\ValidatedInput;
use Hypervel\Tests\Support\Concerns\TestsInteractsWithDataContract;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\VarDumper\VarDumper;

class ValidatedInputTest extends TestCase
{
    use TestsInteractsWithDataContract;

    public function testCanAccessInput(): void
    {
        $input = new ValidatedInput(['name' => 'Taylor', 'votes' => 100]);

        $this->assertSame('Taylor', $input->name);
        $this->assertSame('Taylor', $input['name']);
        $this->assertEquals(['name' => 'Taylor'], $input->all(['name']));
        $this->assertEquals(['name' => 'Taylor'], $input->only(['name']));
        $this->assertEquals(['name' => 'Taylor'], $input->except(['votes']));
        $this->assertEquals(['name' => 'Taylor', 'votes' => 100], $input->all());
    }

    public function testAllSelectsZeroAndEmptyStringKeys(): void
    {
        $input = new ValidatedInput([0 => 'first', '' => 'empty', 'name' => 'Taylor']);

        $this->assertSame([0 => 'first'], $input->all(0));
        $this->assertSame($input->all([0]), $input->all('0'));
        $this->assertSame(['' => 'empty'], $input->all(''));
        $this->assertSame([0 => 'first', 'missing' => null], $input->all('0', 'missing'));
        $this->assertSame([], $input->all([]));
    }

    public function testCanMergeItems(): void
    {
        $input = new ValidatedInput(['name' => 'Taylor']);

        $input = $input->merge(['votes' => 100]);

        $this->assertSame('Taylor', $input->name);
        $this->assertSame('Taylor', $input['name']);
        $this->assertEquals(['name' => 'Taylor'], $input->only(['name']));
        $this->assertEquals(['name' => 'Taylor'], $input->except(['votes']));
        $this->assertEquals(['name' => 'Taylor', 'votes' => 100], $input->all());
    }

    public function testExistsMethod(): void
    {
        $this->assertDataExistsContract();
        $this->assertDataExistsNestedContract();
    }

    public function testHasMethod(): void
    {
        $this->assertDataHasContract();
        $this->assertDataHasNestedContract();
    }

    public function testHasAnyMethod(): void
    {
        $this->assertDataHasAnyContract();
        $this->assertDataHasAnyNestedContract();
    }

    public function testWhenHasMethod(): void
    {
        $this->assertDataWhenHasContract();
        $this->assertDataWhenHasNestedContract();
    }

    public function testFilledMethod(): void
    {
        $this->assertDataFilledContract();
        $this->assertDataFilledNestedContract();
    }

    public function testIsNotFilledMethod(): void
    {
        $this->assertDataIsNotFilledContract();
        $this->assertDataIsNotFilledNestedContract();
    }

    public function testAnyFilledMethod(): void
    {
        $this->assertDataAnyFilledContract();
        $this->assertDataAnyFilledNestedContract();
    }

    public function testWhenFilledMethod(): void
    {
        $this->assertDataWhenFilledContract();
        $this->assertDataWhenFilledNestedContract();
    }

    public function testWhenEnumMethod(): void
    {
        $this->assertDataWhenEnumContract();
    }

    public function testMissingMethod(): void
    {
        $this->assertDataMissingContract();
        $this->assertDataMissingNestedContract();
    }

    public function testWhenMissingMethod(): void
    {
        $this->assertDataWhenMissingContract();
        $this->assertDataWhenMissingNestedContract();
    }

    public function testKeysMethod(): void
    {
        $input = new ValidatedInput(['name' => 'Fatih', 'surname' => 'AYDIN', 'foo' => ['bar' => null, 'baz' => '']]);

        $this->assertEquals(['name', 'surname', 'foo'], $input->keys());
    }

    public function testAllMethod(): void
    {
        $input = new ValidatedInput(['name' => 'Fatih', 'surname' => 'AYDIN', 'foo' => ['bar' => null, 'baz' => '']]);

        $this->assertEquals(['name' => 'Fatih', 'surname' => 'AYDIN', 'foo' => ['bar' => null, 'baz' => '']], $input->all());
    }

    public function testInputMethod(): void
    {
        $input = new ValidatedInput(['name' => 'Fatih', 'surname' => 'AYDIN', 'foo' => ['bar' => null, 'baz' => '']]);

        $this->assertSame('Fatih', $input->input('name'));
        $this->assertNull($input->input('foo.bar'));
        $this->assertSame('test', $input->input('foo.bat', 'test'));
    }

    public function testStrMethod(): void
    {
        $this->assertDataStrContract();
    }

    public function testStringMethod(): void
    {
        $this->assertDataStringContract();
        $this->assertDataStringStrictContract();
    }

    public function testBooleanMethod(): void
    {
        $this->assertDataBooleanContract();
        $this->assertDataBooleanStrictContract();
        $this->assertDataBooleanStrictNativeValuesContract();
    }

    public function testIntegerMethod(): void
    {
        $this->assertDataIntegerContract();
        $this->assertDataIntegerStrictContract();
    }

    public function testFloatMethod(): void
    {
        $this->assertDataFloatContract();
        $this->assertDataFloatStrictContract();
    }

    public function testClampMethod(): void
    {
        $this->assertDataClampContract();
    }

    public function testDateMethod(): void
    {
        $this->assertDataDateContract();
    }

    public function testIntervalMethod(): void
    {
        $this->assertDataIntervalContract();
    }

    public function testEnumMethod(): void
    {
        $this->assertDataEnumContract();
        $this->assertDataEnumNestedContract();
    }

    public function testEnumsMethod(): void
    {
        $this->assertDataEnumsContract();
    }

    public function testArrayMethod(): void
    {
        $this->assertDataArrayContract();
        $this->assertDataArrayArrayableContract();
    }

    public function testFileMethod(): void
    {
        $file = UploadedFile::fake()->create('document.pdf');

        $input = new ValidatedInput([
            'name' => 'Taylor',
            'avatar' => $file,
        ]);

        $this->assertInstanceOf(UploadedFile::class, $input->file('avatar'));
        $this->assertSame($file, $input->file('avatar'));
        $this->assertNull($input->file('name'));
        $this->assertNull($input->file('missing'));
        $this->assertSame('default', $input->file('missing', 'default'));
        $this->assertSame('default', $input->file('name', 'default'));
    }

    #[DataProvider('dumpArguments')]
    public function testDumpPreservesKeySelection(array $arguments, array $expected): void
    {
        $input = new ValidatedInput(['name' => 'Taylor', 'votes' => 100, 'extra' => true]);
        $dumped = [];
        $handler = VarDumper::setHandler(function (mixed $value) use (&$dumped): void {
            $dumped[] = $value;
        });

        try {
            $this->assertSame($input, $input->dump(...$arguments));
            $this->assertSame([$expected], $dumped);
        } finally {
            VarDumper::setHandler($handler);
        }
    }

    /**
     * Provide supported dump argument forms and their selected values.
     */
    public static function dumpArguments(): Generator
    {
        yield 'no keys' => [[], ['name' => 'Taylor', 'votes' => 100, 'extra' => true]];
        yield 'one key' => [['name'], ['name' => 'Taylor']];
        yield 'variadic keys' => [['name', 'votes'], ['name' => 'Taylor', 'votes' => 100]];
        yield 'array keys' => [[['name', 'votes']], ['name' => 'Taylor', 'votes' => 100]];
        yield 'empty array' => [[[]], ['name' => 'Taylor', 'votes' => 100, 'extra' => true]];
        yield 'named array' => [['keys' => ['name']], ['name' => 'Taylor']];
    }

    public function testCollectMethod(): void
    {
        $this->assertDataCollectContract();
        $this->assertDataCollectNestedContract();
    }

    public function testOnlyMethod(): void
    {
        $this->assertDataOnlyContract();
        $this->assertDataOnlyNestedContract();
    }

    public function testExceptMethod(): void
    {
        $this->assertDataExceptContract();
        $this->assertDataExceptNestedContract();
    }

    public function testNullValues(): void
    {
        $this->assertDataNullValuesContract();
        $this->assertDataNullValuesIgnoreDefaultsContract();
        $this->assertDataNestedNullValuesContract();
    }

    /**
     * Create the data instance under test from the given array.
     */
    protected static function arrayToDataFactory(array $data): object
    {
        return new ValidatedInput($data);
    }
}
