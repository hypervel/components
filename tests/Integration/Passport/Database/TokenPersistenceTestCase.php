<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Passport\Concerns\PersistsTokens;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

abstract class TokenPersistenceTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * Create the tables needed to distinguish credential and family failures.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('persistence_tokens', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('partition_id');
            $table->string('user_code');
            $table->string('external_id')->unique();
            $table->unique(['partition_id', 'user_code'], 'Partition_Code');
        });
        $schema->create('persistence_families', static function (Blueprint $table): void {
            $table->string('id')->primary();
        });
    }

    #[DataProvider('collisions')]
    public function testOnlyRegeneratedIdentifiersAreRetried(array $attributes, bool $retry): void
    {
        $this->token()->save();
        $token = $this->token($attributes);
        $writer = $this->writer();
        $connection = $token->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $writer->persistToken($token, ['id', 'user_code']);
                $this->fail('The duplicate insert must fail.');
            } catch (UniqueTokenIdentifierConstraintViolationException|UniqueConstraintViolationException $exception) {
                $this->assertInstanceOf(
                    $retry ? UniqueTokenIdentifierConstraintViolationException::class : UniqueConstraintViolationException::class,
                    $exception
                );
            }

            $this->assertSame(1, $connection->transactionLevel());
            $writer->persistToken($this->token(['id' => 'next', 'user_code' => 'next', 'external_id' => 'next']));
            $this->assertSame(2, $token->newQuery()->count());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(1, $token->newQuery()->count());
    }

    /**
     * Exercise primary, composite generated-code and unrelated unique constraints.
     */
    public static function collisions(): iterable
    {
        yield 'primary key' => [['user_code' => 'other', 'external_id' => 'other'], true];
        yield 'composite code' => [['id' => 'other', 'external_id' => 'other'], true];
        yield 'unrelated constraint' => [['id' => 'other', 'user_code' => 'other'], false];
    }

    public function testFailedTokenInsertRollsBackItsFamilyAndCommitCallback(): void
    {
        $this->token()->save();
        $token = $this->token(['user_code' => 'other', 'external_id' => 'other']);
        $family = (new PersistenceFamily)->forceFill(['id' => 'family']);
        $writer = $this->writer();
        $connection = $token->getConnection();
        $committed = false;
        $connection->beginTransaction();

        try {
            try {
                $writer->persistToken($token, persist: function () use ($writer, $token, $family, $connection, &$committed): void {
                    $writer->saveToken($family);
                    $connection->afterCommitOrNow(static function () use (&$committed): void {
                        $committed = true;
                    });
                    $writer->saveToken($token);
                });
                $this->fail('The duplicate token must fail.');
            } catch (UniqueTokenIdentifierConstraintViolationException) {
                $this->assertSame(0, $family->newQuery()->count());
                $this->assertSame(1, $token->newQuery()->count());
            }

            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $this->assertFalse($committed);
    }

    public function testFamilyPrimaryKeyFailureIsNotTranslatedAsATokenCollision(): void
    {
        PersistenceFamily::forceCreate(['id' => 'family']);
        $family = (new PersistenceFamily)->forceFill(['id' => 'family']);
        $token = $this->token();
        $writer = $this->writer();

        try {
            $writer->persistToken($token, persist: function () use ($writer, $family, $token): void {
                $writer->saveToken($family);
                $writer->saveToken($token);
            });
            $this->fail('The duplicate family must fail.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertStringContainsString('persistence_families', $exception->getSql());
        }

        $this->assertSame(0, $token->newQuery()->count());
        $this->assertSame(1, $family->newQuery()->count());
    }

    public function testObserverCancellationFailsPersistence(): void
    {
        PersistenceToken::creating(static fn (): bool => false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credential persistence was canceled.');
        $this->writer()->persistToken($this->token());
    }

    /**
     * Build a credential with independently unique values.
     */
    protected function token(array $attributes = []): PersistenceToken
    {
        return (new PersistenceToken)->forceFill(array_replace([
            'id' => 'token',
            'partition_id' => 'partition',
            'user_code' => 'code',
            'external_id' => 'external',
        ], $attributes));
    }

    /**
     * Expose the persistence concern without initializing the OAuth server.
     */
    protected function writer(): object
    {
        return new class {
            use PersistsTokens {
                persistToken as public;
                saveToken as public;
            }
        };
    }
}

class PersistenceToken extends Model
{
    public bool $timestamps = false;

    public bool $incrementing = false;

    protected string $keyType = 'string';
}

class PersistenceFamily extends Model
{
    public bool $timestamps = false;

    public bool $incrementing = false;

    protected string $keyType = 'string';
}
