<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Constraints;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Testing\Constraints\HasInDatabase;
use Hypervel\Testing\Constraints\NotSoftDeletedInDatabase;
use Hypervel\Testing\Constraints\SoftDeletedInDatabase;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\ExpectationFailedException;

class DatabaseConstraintsTest extends TestCase
{
    use VerifiesDoubles;

    public function testStringRepresentationsSubstituteInvalidUtf8(): void
    {
        $database = Double::for(Connection::class)->strict();
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
            Double::for(Connection::class)->strict(),
            ['name' => '世界'],
        );

        $description = $constraint->failureDescription('users');

        $this->assertStringContainsString('世界', $description);
        $this->assertStringNotContainsString('\u4e16\u754c', $description);
    }

    public function testHasInDatabaseAssertionFailureIncludesMalformedSimilarResults(): void
    {
        $invalidUtf8 = "\xB1";
        $builder = Double::for(Builder::class)->strict();
        $builder->expects('where')->with(['name' => 'expected'])->returns($builder);
        $builder->expects('exists')->returns(false);
        $builder->expects('where')->with('name', 'expected')->returns($builder);
        $builder->expects('select')->with(['name'])->returns($builder);
        $builder->expects('limit')->with(3)->returns($builder);
        $builder->expects('get')->returns(collect([['name' => $invalidUtf8]]));
        $builder->expects('count')->returns(1);

        $database = Double::for(Connection::class)->strict();
        $database->expects('table')->with('users')->times(2)->returns($builder);

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
        $similarBuilder = Double::for(Builder::class)->strict();
        $similarBuilder->expects('where')->with('name', 'expected')->returns($similarBuilder);
        $similarBuilder->expects('select')->with(['name'])->returns($similarBuilder);
        $similarBuilder->expects('limit')->with(3)->returns($similarBuilder);
        $similarBuilder->expects('get')->returns(collect());

        $fallbackBuilder = Double::for(Builder::class)->strict();
        $fallbackBuilder->expects('select')->with(['name'])->returns($fallbackBuilder);
        $fallbackBuilder->expects('limit')->with(3)->returns($fallbackBuilder);
        $fallbackBuilder->expects('get')->returns(collect([['name' => $invalidUtf8]]));
        $fallbackBuilder->expects('count')->returns(1);

        $database = Double::for(Connection::class)->strict();
        $database->expects('table')->with('users')->times(2)->returns($similarBuilder, $fallbackBuilder);

        $constraint = new ExposedHasInDatabase($database, ['name' => 'expected']);
        $description = $constraint->additionalInfo('users');

        $this->assertStringContainsString('Found:', $description);
        $this->assertStringContainsString("\u{FFFD}", $description);
    }

    public function testSoftDeleteAdditionalInfoSubstitutesMalformedResults(): void
    {
        foreach ([ExposedSoftDeletedInDatabase::class, ExposedNotSoftDeletedInDatabase::class] as $constraintClass) {
            $builder = Double::for(Builder::class)->strict();
            $builder->expects('limit')->with(3)->returns($builder);
            $builder->expects('get')->returns(collect([['name' => "\xB1"]]));
            $builder->expects('count')->returns(1);

            $database = Double::for(Connection::class)->strict();
            $database->expects('table')->with('users')->returns($builder);

            $constraint = new $constraintClass($database, ['name' => 'expected'], 'deleted_at');

            $this->assertStringContainsString('\ufffd', $constraint->additionalInfo('users'));
        }
    }

    public function testStringRepresentationUsesPartialOutputForNonFiniteNumbers(): void
    {
        $constraint = new HasInDatabase(Double::for(Connection::class)->strict(), ['number' => INF]);

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
