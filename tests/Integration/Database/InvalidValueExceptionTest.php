<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\InvalidValueException;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Throwable;

class InvalidValueExceptionTest extends DatabaseTestCase
{
    /**
     * Create the table used by the invalid value tests.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_invalid_value', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });

        DB::table('test_invalid_value')->insert(['id' => 12, 'name' => 'Taylor']);
    }

    /**
     * Capture the exception thrown by the given callback.
     */
    private function captureFrom(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('No exception was thrown.');
    }

    #[RequiresDatabase('pgsql')]
    public function testValuesThatAreNotValidForTheColumnTypeArePromoted(): void
    {
        $e = $this->captureFrom(fn (): ?object => DB::table('test_invalid_value')->where('id', 'abc')->first());

        $this->assertInstanceOf(InvalidValueException::class, $e);
        $this->assertSame('22P02', $e->getCode());
    }

    #[RequiresDatabase('pgsql')]
    public function testValuesOutOfRangeForTheColumnWidthArePromoted(): void
    {
        $e = $this->captureFrom(fn (): ?object => DB::table('test_invalid_value')->where('id', '3000000000')->first());

        $this->assertInstanceOf(InvalidValueException::class, $e);
        $this->assertSame('22003', $e->getCode());
    }

    #[RequiresDatabase('pgsql')]
    public function testValuesWithinTheColumnWidthAreNotPromoted(): void
    {
        $this->assertNull(DB::table('test_invalid_value')->where('id', '2000000000')->first());
        $this->assertSame('Taylor', DB::table('test_invalid_value')->where('id', '12')->first()->name);
    }

    #[RequiresDatabase('pgsql')]
    public function testUndefinedColumnsAreNotPromoted(): void
    {
        $e = $this->captureFrom(fn (): ?object => DB::table('test_invalid_value')->where('missing_column', 'x')->first());

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertNotInstanceOf(InvalidValueException::class, $e);
    }

    public function testUndefinedTablesAreNotPromoted(): void
    {
        $e = $this->captureFrom(fn (): ?object => DB::table('table_that_does_not_exist')->where('id', 1)->first());

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertNotInstanceOf(InvalidValueException::class, $e);
    }

    public function testSyntaxErrorsAreNotPromoted(): void
    {
        $e = $this->captureFrom(fn (): array => DB::select('selct * from test_invalid_value'));

        $this->assertInstanceOf(QueryException::class, $e);
        $this->assertNotInstanceOf(InvalidValueException::class, $e);
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testWrittenValuesOutOfRangeForTheColumnWidthArePromoted(): void
    {
        $e = $this->captureFrom(fn (): bool => DB::table('test_invalid_value')->insert(['id' => 3000000000, 'name' => 'Abigail']));

        $this->assertInstanceOf(InvalidValueException::class, $e);
        $this->assertSame('22003', $e->getCode());
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testWrittenValuesThatAreNotValidForTheColumnTypeArePromoted(): void
    {
        // MySQL reports this as a general error, while MariaDB uses SQLSTATE 22007.
        $e = $this->captureFrom(fn (): bool => DB::table('test_invalid_value')->insert(['id' => 'abc', 'name' => 'Abigail']));

        $this->assertInstanceOf(InvalidValueException::class, $e);
        $this->assertStringContainsString('1366 Incorrect integer value', $e->getMessage());
    }
}
