<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\ClassMap;

use Composer\Autoload\ClassLoader;
use Countable;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Composer;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ClassMapManagerTest extends TestCase
{
    private ClassLoader $originalLoader;

    private ClassLoader $isolatedLoader;

    private Filesystem $files;

    private string $directory;

    /**
     * Prepare isolated source files and an application loader.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalLoader = Composer::getLoader();
        $this->isolatedLoader = new ClassLoader;
        $this->isolatedLoader->register();
        Composer::setLoader($this->isolatedLoader);
        $this->files = new Filesystem;
        $this->directory = ParallelTesting::tempDir('ClassMapManagerTest');
        $this->files->deleteDirectory($this->directory);
        $this->files->ensureDirectoryExists($this->directory);
    }

    /**
     * Restore the application loader and remove fixture sources.
     */
    protected function tearDown(): void
    {
        $this->isolatedLoader->unregister();
        Composer::setLoader($this->originalLoader);
        $this->files->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function testHasEntriesReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse(ClassMapManager::hasEntries());
    }

    public function testAddRegistersEntriesAndAppliesToAutoloader(): void
    {
        $class = __NAMESPACE__ . '\Replacement' . bin2hex(random_bytes(4));
        $path = $this->directory . '/Replacement.php';
        $this->writeSource($path, $class, 'replacement');
        $applicationMap = $this->isolatedLoader->getClassMap();

        ClassMapManager::add([$class => $path]);

        $this->assertTrue(ClassMapManager::hasEntries());
        $this->assertSame([$class => $path], ClassMapManager::getEntries());
        $this->assertSame($applicationMap, $this->isolatedLoader->getClassMap());
        $this->assertSame('replacement', (new $class)->value());
    }

    public function testLoadedReplacementCanBeRegisteredAgainAfterReset(): void
    {
        $class = __NAMESPACE__ . '\Repeated' . bin2hex(random_bytes(4));
        $path = $this->directory . '/Repeated.php';
        $this->writeSource($path, $class, 'repeated');
        ClassMapManager::add([$class => $path]);
        $this->assertSame('repeated', (new $class)->value());

        ClassMapManager::flushState();
        $equivalentPath = $this->directory . '/./Repeated.php';
        ClassMapManager::add([$class => $equivalentPath]);

        $this->assertSame([$class => $equivalentPath], ClassMapManager::getEntries());
        $this->assertSame('repeated', (new $class)->value());
    }

    public function testAddThrowsWhenClassAlreadyLoaded(): void
    {
        // This test class itself is already loaded
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Cannot override class map for [' . self::class . ']');

        ClassMapManager::add([
            self::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddThrowsWhenInterfaceAlreadyLoaded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Cannot override class map');

        ClassMapManager::add([
            Countable::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddThrowsWhenTraitAlreadyLoaded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Cannot override class map');

        ClassMapManager::add([
            LoadedTraitForClassMapTest::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddMergesMultipleCalls(): void
    {
        ClassMapManager::add([
            'Fake\ClassA' => '/tmp/a.php',
        ]);
        ClassMapManager::add([
            'Fake\ClassB' => '/tmp/b.php',
        ]);

        $this->assertSame([
            'Fake\ClassA' => '/tmp/a.php',
            'Fake\ClassB' => '/tmp/b.php',
        ], ClassMapManager::getEntries());
    }

    public function testFlushStateRemovesAllEntries(): void
    {
        $class = __NAMESPACE__ . '\Unloaded' . bin2hex(random_bytes(4));
        $original = $this->directory . '/Original.php';
        $replacement = $this->directory . '/Replacement.php';
        $this->writeSource($original, $class, 'original');
        $this->writeSource($replacement, $class, 'replacement');
        $this->isolatedLoader->addClassMap([$class => $original]);
        ClassMapManager::add([$class => $replacement]);

        ClassMapManager::flushState();

        $this->assertFalse(ClassMapManager::hasEntries());
        $this->assertSame([], ClassMapManager::getEntries());
        $this->assertSame('original', (new $class)->value());
    }

    /**
     * Write a class source that identifies the implementation being loaded.
     */
    private function writeSource(string $path, string $class, string $value): void
    {
        $shortName = substr($class, strrpos($class, '\\') + 1);
        $namespace = __NAMESPACE__;
        $this->files->put($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

class {$shortName}
{
    public function value(): string
    {
        return '{$value}';
    }
}
PHP);
    }
}

trait LoadedTraitForClassMapTest
{
}
