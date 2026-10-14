<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Passport\Bridge\DeviceCodeRepository;
use Hypervel\Passport\DeviceCode;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[ResetRefreshDatabaseState]
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
                $writer->persistCode($token);
                $this->fail('The duplicate insert must fail.');
            } catch (UniqueTokenIdentifierConstraintViolationException|UniqueConstraintViolationException $exception) {
                $this->assertInstanceOf(
                    $retry ? UniqueTokenIdentifierConstraintViolationException::class : UniqueConstraintViolationException::class,
                    $exception
                );
            }

            $this->assertSame(1, $connection->transactionLevel());
            $writer->persistCode($this->token(['id' => 'next', 'user_code' => 'next', 'external_id' => 'next']));
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

    public function testFailedDeviceInsertRollsBackObserverWritesAndCommitCallbacks(): void
    {
        $this->token()->save();
        $token = $this->token(['user_code' => 'other', 'external_id' => 'other']);
        $family = (new PersistenceFamily)->forceFill(['id' => 'family']);
        $writer = $this->writer();
        $connection = $token->getConnection();
        $committed = false;
        $connection->beginTransaction();
        PersistenceToken::creating(static function () use ($family, $connection, &$committed): void {
            $family->save();
            $connection->afterCommitOrNow(static function () use (&$committed): void {
                $committed = true;
            });
        });

        try {
            try {
                $writer->persistCode($token);
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

    public function testObserverPrimaryKeyFailureIsNotTranslatedAsADeviceCollision(): void
    {
        PersistenceFamily::forceCreate(['id' => 'family']);
        $family = (new PersistenceFamily)->forceFill(['id' => 'family']);
        $token = $this->token();
        $writer = $this->writer();
        PersistenceToken::creating(static function () use ($family): void {
            $family->save();
        });

        try {
            $writer->persistCode($token);
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
        $this->writer()->persistCode($this->token());
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
     * Expose device persistence without initializing the OAuth server.
     */
    protected function writer(): object
    {
        return new class extends DeviceCodeRepository {
            /**
             * Persist a device model with the test's custom constraints.
             */
            public function persistCode(DeviceCode $code): void
            {
                $this->persistNewDeviceCode($code);
            }
        };
    }
}

class PersistenceToken extends DeviceCode
{
    protected ?string $table = 'persistence_tokens';
}

class PersistenceFamily extends Model
{
    public bool $timestamps = false;

    public bool $incrementing = false;

    protected string $keyType = 'string';
}
