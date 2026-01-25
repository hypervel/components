<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Listeners;

use Hyperf\Context\ApplicationContext;
use Hyperf\Framework\Event\BootApplication;
use Hypervel\Database\Connection;
use Hypervel\Database\Listeners\RegisterSQLiteConnectionListener;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Testbench\TestCase;
use PDO;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class RegisterSQLiteConnectionListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear any registered resolvers to avoid test pollution
        $this->clearSQLiteResolver();

        // Clear any persistent PDOs stored in container
        $this->clearPersistentPdos();

        parent::tearDown();
    }

    public function testListensToBootApplicationEvent(): void
    {
        $listener = new RegisterSQLiteConnectionListener($this->app);

        $this->assertSame([BootApplication::class], $listener->listen());
    }

    public function testRegistersResolverForSQLiteDriver(): void
    {
        // Clear any existing resolver
        $this->clearSQLiteResolver();
        $this->assertNull(Connection::getResolver('sqlite'));

        $listener = new RegisterSQLiteConnectionListener($this->app);
        $listener->process(new BootApplication());

        $this->assertNotNull(Connection::getResolver('sqlite'));
    }

    public function testResolverReturnsSQLiteConnection(): void
    {
        $listener = new RegisterSQLiteConnectionListener($this->app);
        $listener->process(new BootApplication());

        $resolver = Connection::getResolver('sqlite');
        $pdo = new PDO('sqlite::memory:');
        $connection = $resolver($pdo, ':memory:', '', ['database' => '/tmp/test.db', 'name' => 'test']);

        $this->assertInstanceOf(SQLiteConnection::class, $connection);
    }

    /**
     * @dataProvider inMemoryDatabaseProvider
     */
    public function testIsInMemoryDatabaseDetection(string $database, bool $expected): void
    {
        $listener = new RegisterSQLiteConnectionListener($this->app);

        $method = new ReflectionMethod($listener, 'isInMemoryDatabase');

        $this->assertSame($expected, $method->invoke($listener, $database));
    }

    public static function inMemoryDatabaseProvider(): array
    {
        return [
            'standard memory' => [':memory:', true],
            'query string mode=memory' => ['file:test?mode=memory', true],
            'ampersand mode=memory' => ['file:test?cache=shared&mode=memory', true],
            'mode=memory at end' => ['file:test?other=value&mode=memory', true],
            'regular file path' => ['/tmp/database.sqlite', false],
            'relative path' => ['database.sqlite', false],
            'empty string' => ['', false],
            'memory in path name' => ['/tmp/memory.sqlite', false],
            'mode_memory without equals' => ['file:test?mode_memory', false],
        ];
    }

    public function testPersistentPdoIsSharedForInMemoryDatabase(): void
    {
        $listener = new RegisterSQLiteConnectionListener($this->app);
        $listener->process(new BootApplication());

        $resolver = Connection::getResolver('sqlite');

        $config = ['database' => ':memory:', 'name' => 'test_memory'];

        // Create a PDO closure that creates a new PDO each time
        $pdoFactory = fn () => new PDO('sqlite::memory:');

        // First call should create and store the PDO
        $connection1 = $resolver($pdoFactory, ':memory:', '', $config);
        $pdo1 = $connection1->getPdo();

        // Second call should return the same PDO
        $connection2 = $resolver($pdoFactory, ':memory:', '', $config);
        $pdo2 = $connection2->getPdo();

        $this->assertSame($pdo1, $pdo2, 'In-memory database should share the same PDO instance');
    }

    public function testFileDatabaseDoesNotSharePdo(): void
    {
        $listener = new RegisterSQLiteConnectionListener($this->app);
        $listener->process(new BootApplication());

        $resolver = Connection::getResolver('sqlite');

        // Create a temp file for testing
        $tempFile = sys_get_temp_dir() . '/test_sqlite_' . uniqid() . '.db';
        touch($tempFile);

        try {
            $config = ['database' => $tempFile, 'name' => 'test_file'];

            // Create a PDO closure that creates a new PDO each time
            $pdoFactory = fn () => new PDO("sqlite:{$tempFile}");

            // Each call should create a new PDO
            $connection1 = $resolver($pdoFactory, $tempFile, '', $config);
            $pdo1 = $connection1->getPdo();

            $connection2 = $resolver($pdoFactory, $tempFile, '', $config);
            $pdo2 = $connection2->getPdo();

            $this->assertNotSame($pdo1, $pdo2, 'File-based database should NOT share PDO instances');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testDifferentNamedInMemoryConnectionsGetDifferentPdos(): void
    {
        $listener = new RegisterSQLiteConnectionListener($this->app);
        $listener->process(new BootApplication());

        $resolver = Connection::getResolver('sqlite');

        $pdoFactory = fn () => new PDO('sqlite::memory:');

        $connection1 = $resolver($pdoFactory, ':memory:', '', ['database' => ':memory:', 'name' => 'memory_one']);
        $pdo1 = $connection1->getPdo();

        $connection2 = $resolver($pdoFactory, ':memory:', '', ['database' => ':memory:', 'name' => 'memory_two']);
        $pdo2 = $connection2->getPdo();

        $this->assertNotSame($pdo1, $pdo2, 'Different named connections should have different PDO instances');
    }

    protected function clearSQLiteResolver(): void
    {
        // Use reflection to clear the resolver
        $property = new \ReflectionProperty(Connection::class, 'resolvers');
        $resolvers = $property->getValue();
        unset($resolvers['sqlite']);
        $property->setValue(null, $resolvers);
    }

    protected function clearPersistentPdos(): void
    {
        $container = ApplicationContext::getContainer();

        // Clear any test PDOs we may have created
        foreach (['test_memory', 'memory_one', 'memory_two', 'test_file', 'test', 'default'] as $name) {
            $key = "sqlite.persistent.pdo.{$name}";
            if ($container->has($key)) {
                // Can't actually unset from container, but it will be garbage collected
            }
        }
    }
}
