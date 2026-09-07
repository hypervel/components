<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Constraints;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Testing\Constraints\HasInDatabase;
use Hypervel\Testing\Constraints\NotSoftDeletedInDatabase;
use Hypervel\Testing\Constraints\SoftDeletedInDatabase;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\ExpectationFailedException;

class DatabaseConstraintsTest extends TestCase
{
    public function testStringRepresentationsSubstituteInvalidUtf8(): void
    {
        $database = m::mock(Connection::class);
        $invalidUtf8 = "\xB1";
        $constraints = [
            new HasInDatabase($database, ['value' => $invalidUtf8]),
            new SoftDeletedInDatabase($database, ['value' => $invalidUtf8], 'deleted_at'),
            new NotSoftDeletedInDatabase($database, ['value' => $invalidUtf8], 'deleted_at'),
        ];

        foreach ($constraints as $constraint) {
            $description = $constraint->toString();

            $this->assertStringContainsString('\ufffd', $description);
        }
    }

    public function testHasInDatabaseFailureDescriptionKeepsUnicodeReadable(): void
    {
        $constraint = new HasInDatabaseWithoutAdditionalInfo(
            m::mock(Connection::class),
            ['name' => '世界'],
        );

        $description = $constraint->failureDescription('users');

        $this->assertStringContainsString('世界', $description);
        $this->assertStringNotContainsString('\u4e16\u754c', $description);
    }

    public function testHasInDatabaseAssertionFailureIncludesMalformedSimilarResults(): void
    {
        $invalidUtf8 = "\xB1";
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('where')->with(['name' => 'expected'])->once()->andReturnSelf();
        $builder->shouldReceive('exists')->once()->andReturnFalse();
        $builder->shouldReceive('where')->with('name', 'expected')->once()->andReturnSelf();
        $builder->shouldReceive('select')->with(['name'])->once()->andReturnSelf();
        $builder->shouldReceive('limit')->with(3)->once()->andReturnSelf();
        $builder->shouldReceive('get')->once()->andReturn(collect([['name' => $invalidUtf8]]));
        $builder->shouldReceive('count')->once()->andReturn(1);

        $database = m::mock(Connection::class);
        $database->shouldReceive('table')->with('users')->twice()->andReturn($builder);

        $failure = null;

        try {
            $this->assertThat('users', new HasInDatabase($database, ['name' => 'expected']));
        } catch (ExpectationFailedException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(ExpectationFailedException::class, $failure);
        $this->assertStringContainsString('Found similar results', $failure->getMessage());
        $this->assertStringContainsString("\u{FFFD}", $failure->getMessage());
    }

    public function testHasInDatabaseAdditionalInfoIncludesMalformedFallbackResults(): void
    {
        $invalidUtf8 = "\xB1";
        $similarBuilder = m::mock(Builder::class);
        $similarBuilder->shouldReceive('where')->with('name', 'expected')->once()->andReturnSelf();
        $similarBuilder->shouldReceive('select')->with(['name'])->once()->andReturnSelf();
        $similarBuilder->shouldReceive('limit')->with(3)->once()->andReturnSelf();
        $similarBuilder->shouldReceive('get')->once()->andReturn(collect());

        $fallbackBuilder = m::mock(Builder::class);
        $fallbackBuilder->shouldReceive('select')->with(['name'])->once()->andReturnSelf();
        $fallbackBuilder->shouldReceive('limit')->with(3)->once()->andReturnSelf();
        $fallbackBuilder->shouldReceive('get')->once()->andReturn(collect([['name' => $invalidUtf8]]));
        $fallbackBuilder->shouldReceive('count')->once()->andReturn(1);

        $database = m::mock(Connection::class);
        $database->shouldReceive('table')->with('users')->twice()->andReturn($similarBuilder, $fallbackBuilder);

        $constraint = new ExposedHasInDatabase($database, ['name' => 'expected']);
        $description = $constraint->additionalInfo('users');

        $this->assertStringContainsString('Found:', $description);
        $this->assertStringContainsString("\u{FFFD}", $description);
    }

    public function testSoftDeleteAdditionalInfoSubstitutesMalformedResults(): void
    {
        foreach ([ExposedSoftDeletedInDatabase::class, ExposedNotSoftDeletedInDatabase::class] as $constraintClass) {
            $builder = m::mock(Builder::class);
            $builder->shouldReceive('limit')->with(3)->once()->andReturnSelf();
            $builder->shouldReceive('get')->once()->andReturn(collect([['name' => "\xB1"]]));
            $builder->shouldReceive('count')->once()->andReturn(1);

            $database = m::mock(Connection::class);
            $database->shouldReceive('table')->with('users')->once()->andReturn($builder);

            $constraint = new $constraintClass($database, ['name' => 'expected'], 'deleted_at');

            $this->assertStringContainsString('\ufffd', $constraint->additionalInfo('users'));
        }
    }

    public function testStringRepresentationUsesPartialOutputForNonFiniteNumbers(): void
    {
        $constraint = new HasInDatabase(m::mock(Connection::class), ['number' => INF]);

        $this->assertSame('{"number":0}', $constraint->toString());
    }
}

class HasInDatabaseWithoutAdditionalInfo extends HasInDatabase
{
    protected function getAdditionalInfo($table): string
    {
        return 'No additional rows';
    }
}

class ExposedHasInDatabase extends HasInDatabase
{
    public function additionalInfo(string $table): string
    {
        return $this->getAdditionalInfo($table);
    }
}

class ExposedSoftDeletedInDatabase extends SoftDeletedInDatabase
{
    public function additionalInfo(string $table): string
    {
        return $this->getAdditionalInfo($table);
    }
}

class ExposedNotSoftDeletedInDatabase extends NotSoftDeletedInDatabase
{
    public function additionalInfo(string $table): string
    {
        return $this->getAdditionalInfo($table);
    }
}
