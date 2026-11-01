<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Http\Middleware;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Http\Middleware\PreventStaleDatabaseReads;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

class PreventStaleDatabaseReadsTest extends TestCase
{
    protected string $directory;

    protected function defineEnvironment(Application $app): void
    {
        $this->directory = ParallelTesting::tempDir('PreventStaleDatabaseReadsTest');
        $files = $app->make('files');
        $files->deleteDirectory($this->directory);
        $files->ensureDirectoryExists($this->directory);
        $files->put($this->directory . '/read.sqlite', '');
        $files->put($this->directory . '/write.sqlite', '');

        // In-memory SQLite deliberately shares one PDO; distinct files exercise read routing.
        $connection = [
            'driver' => 'sqlite',
            'database' => $this->directory . '/write.sqlite',
            'read' => ['database' => $this->directory . '/read.sqlite'],
            'write' => ['database' => $this->directory . '/write.sqlite'],
        ];

        $app->make('config')->set([
            'database.default' => 'testing',
            'database.connections.testing' => $connection,
            'database.connections.other' => $connection,
            'session.driver' => 'array',
        ]);
    }

    protected function defineRoutes(Router $router): void
    {
        $router->middleware([StartSession::class, PreventStaleDatabaseReads::class])->group(function (Router $router): void {
            $router->get('read', fn () => DB::select('select 1'));
            $router->post('write', fn () => DB::statement('select 1'));
            $router->get('no-query', fn () => (string) $this->app->make(PoolManager::class)->pool('testing')->getBorrowedCount());
            $router->post('change-default', function (): string {
                DB::statement('select 1');
                DB::setDefaultConnection('other');

                return 'ok';
            });
        });

        $router->middleware([StartSession::class, PreventStaleDatabaseReads::for(10)])->group(function (Router $router): void {
            $router->get('read-later', fn () => DB::select('select 1'));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }

    public function testReadsUseTheReadConnectionWithoutARecentWrite(): void
    {
        DB::enableQueryLog();

        $this->get('read')->assertOk();

        $this->assertSame('read', DB::getQueryLog()[0]['readWriteType']);
    }

    public function testReadsUseTheWriteConnectionAfterAWrite(): void
    {
        $this->assertNull(DB::getHeldConnection());

        $this->post('write')->assertOk()
            ->assertSessionHas('_database_modified_at', Date::now()->getTimestamp());

        DB::forgetRecordModificationState();
        DB::enableQueryLog();

        $this->travel(2)->seconds();

        $this->get('read')->assertOk();

        $this->assertSame('write', DB::getQueryLog()[0]['readWriteType']);
    }

    public function testReadsUseTheReadConnectionOnceTheDelayHasPassed(): void
    {
        $this->post('write')->assertOk();

        DB::forgetRecordModificationState();
        DB::enableQueryLog();

        $this->travel(3)->seconds();

        $this->get('read')->assertOk();

        $this->assertSame('read', DB::getQueryLog()[0]['readWriteType']);
    }

    public function testTheDelayCanBeCustomized(): void
    {
        $this->post('write')->assertOk();

        DB::forgetRecordModificationState();
        DB::enableQueryLog();

        $this->travel(10)->seconds();

        $this->get('read-later')->assertOk();

        $this->assertSame('write', DB::getQueryLog()[0]['readWriteType']);
    }

    public function testTheConnectionStopsBeingStickyOnceTheDelayHasPassed(): void
    {
        $this->post('write')->assertOk();

        DB::forgetRecordModificationState();

        $this->get('read')->assertOk();

        DB::enableQueryLog();

        $this->travel(3)->seconds();

        $this->get('read')->assertOk();

        $this->assertSame('read', DB::getQueryLog()[0]['readWriteType']);
    }

    #[WithConfig('database.connections.testing.pool.testing_enabled', true)]
    public function testRequestsWithoutQueriesDoNotBorrowAConnection(): void
    {
        $pool = $this->app->make(PoolManager::class)->pool('testing');

        $this->get('no-query')->assertOk()->assertContent('0');

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertNull(DB::getHeldConnection());
    }

    #[WithConfig('database.connections.testing.pool.testing_enabled', true)]
    public function testWritesAreTrackedOnTheOriginalDefaultConnection(): void
    {
        $this->post('change-default')->assertOk()
            ->assertSessionHas('_database_modified_at', Date::now()->getTimestamp());
    }

    #[WithConfig('database.connections.testing.pool.testing_enabled', true)]
    public function testAnAlreadyHeldConnectionStopsUsingTheWriterWithoutARecentWrite(): void
    {
        $connection = DB::connection();
        $connection->useWriteConnectionWhenReading();
        $connection->enableQueryLog();
        $request = Request::create('/read');
        $request->setHypervelSession($this->app->make('session')->driver());

        $this->app->make(PreventStaleDatabaseReads::class)->handle($request, function () use ($connection): array {
            return $connection->select('select 1');
        });

        $this->assertSame('read', $connection->getQueryLog()[0]['readWriteType']);
    }
}
