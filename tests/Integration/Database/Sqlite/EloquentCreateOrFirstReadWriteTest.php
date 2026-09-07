<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite\EloquentCreateOrFirstReadWriteTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\Database\Sqlite\SqliteTestCase;
use UnitEnum;

class EloquentCreateOrFirstReadWriteTest extends SqliteTestCase
{
    protected static string $databaseDirectory;

    protected static string $readPath;

    protected static string $writePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $filesystem = new Filesystem;
        static::$databaseDirectory = ParallelTesting::tempDir('EloquentCreateOrFirstReadWriteTest');
        $filesystem->deleteDirectory(static::$databaseDirectory);
        $filesystem->ensureDirectoryExists(static::$databaseDirectory);

        static::$readPath = static::$databaseDirectory . '/read.sqlite';
        static::$writePath = static::$databaseDirectory . '/write.sqlite';
        touch(static::$readPath);
        touch(static::$writePath);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(static::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('database.connections.collision_read', [
            'driver' => 'sqlite',
            'database' => static::$readPath,
            'prefix' => '',
        ]);
        $config->set('database.connections.collision_write', [
            'driver' => 'sqlite',
            'database' => static::$writePath,
            'prefix' => '',
        ]);
        $config->set('database.connections.collision_split', [
            'driver' => 'sqlite',
            'read' => ['database' => static::$readPath],
            'write' => ['database' => static::$writePath],
            'sticky' => false,
            'prefix' => '',
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        $this->refreshSchema('collision_read');
        $this->refreshSchema('collision_write');
    }

    public function testHasManyThroughCollisionFallbackReadsTheWriter(): void
    {
        DB::connection('collision_write')->table('read_write_through')->insert([
            'id' => 10,
            'source_id' => 1,
        ]);
        DB::connection('collision_write')->table('read_write_children')->insert([
            'id' => 100,
            'through_id' => 10,
            'name' => 'winner',
        ]);

        $source = new ReadWriteSource;
        $source->id = 1;
        $source->exists = true;

        $result = $source->children()->createOrFirst(['name' => 'winner']);

        $this->assertSame(100, $result->id);
        $this->assertFalse($result->wasRecentlyCreated);
    }

    public function testBelongsToManyCollisionFallbackReadsTheWriter(): void
    {
        DB::connection('collision_write')->table('read_write_related')->insert([
            'id' => 200,
            'name' => 'winner',
        ]);
        DB::connection('collision_write')->table('read_write_pivot')->insert([
            'source_id' => 1,
            'related_id' => 200,
        ]);

        $source = new ReadWriteSource;
        $source->id = 1;
        $source->exists = true;

        $result = $source->related()->createOrFirst(['name' => 'winner'], touch: false);

        $this->assertSame(200, $result->id);
        $this->assertFalse($result->wasRecentlyCreated);
    }

    /**
     * Recreate the read/write regression schema on one physical database.
     */
    protected function refreshSchema(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->dropIfExists('read_write_pivot');
        $schema->dropIfExists('read_write_related');
        $schema->dropIfExists('read_write_children');
        $schema->dropIfExists('read_write_through');

        $schema->create('read_write_through', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('source_id');
        });

        $schema->create('read_write_children', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('through_id')->nullable();
            $table->string('name')->unique();
        });

        $schema->create('read_write_related', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name')->unique();
        });

        $schema->create('read_write_pivot', function (Blueprint $table): void {
            $table->integer('source_id');
            $table->integer('related_id');
            $table->unique(['source_id', 'related_id']);
        });
    }
}

class ReadWriteSource extends Model
{
    protected UnitEnum|string|null $connection = 'collision_split';

    protected ?string $table = 'read_write_sources';

    public bool $timestamps = false;

    /**
     * Get the children through the intermediate table.
     */
    public function children(): HasManyThrough
    {
        return $this->hasManyThrough(
            ReadWriteChild::class,
            ReadWriteThrough::class,
            'source_id',
            'through_id',
        );
    }

    /**
     * Get the related models.
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(
            ReadWriteRelated::class,
            'read_write_pivot',
            'source_id',
            'related_id',
        );
    }
}

class ReadWriteThrough extends Model
{
    protected UnitEnum|string|null $connection = 'collision_split';

    protected ?string $table = 'read_write_through';

    public bool $timestamps = false;
}

class ReadWriteChild extends Model
{
    protected UnitEnum|string|null $connection = 'collision_split';

    protected ?string $table = 'read_write_children';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class ReadWriteRelated extends Model
{
    protected UnitEnum|string|null $connection = 'collision_split';

    protected ?string $table = 'read_write_related';

    protected array $guarded = [];

    public bool $timestamps = false;
}
