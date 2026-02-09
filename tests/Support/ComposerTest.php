<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Composer\Autoload\ClassLoader;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ComposerTest extends TestCase
{
    /**
     * Saved Composer state, restored in tearDown to avoid polluting other tests.
     *
     * Composer is a static class with process-global cached state. The Testbench
     * Bootstrapper populates this cache from a generated workbench composer.lock.
     * We must save and restore it because setBasePath() calls reset() internally.
     */
    private array $savedComposerState = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Save Composer's current static state before overriding
        $this->savedComposerState = (fn () => [
            'basePath' => static::$basePath,
            'content' => static::$content,
            'json' => static::$json,
            'extra' => static::$extra,
            'scripts' => static::$scripts,
            'versions' => static::$versions,
        ])->bindTo(null, Composer::class)();

        // Point at our fixture (this calls reset() internally)
        Composer::setBasePath(__DIR__ . '/fixtures/composer');
    }

    protected function tearDown(): void
    {
        // Restore Composer's static state to what it was before this test
        (function (array $state) {
            static::$basePath = $state['basePath'];
            static::$content = $state['content'];
            static::$json = $state['json'];
            static::$extra = $state['extra'];
            static::$scripts = $state['scripts'];
            static::$versions = $state['versions'];
        })->bindTo(null, Composer::class)($this->savedComposerState);

        parent::tearDown();
    }

    public function testGetLoader()
    {
        $loader = Composer::getLoader();

        $this->assertInstanceOf(ClassLoader::class, $loader);
    }

    public function testSetAndGetLoader()
    {
        $original = Composer::getLoader();
        $custom = new ClassLoader();

        Composer::setLoader($custom);

        $this->assertSame($custom, Composer::getLoader());

        Composer::setLoader($original);
    }

    public function testGetLockContent()
    {
        $content = Composer::getLockContent();
        $this->assertNotEmpty($content);
        $this->assertNotNull($content->offsetGet('packages'));
    }

    public function testGetMergedExtraWithoutKeyReturnsAll()
    {
        $extra = Composer::getMergedExtra();
        $this->assertIsArray($extra);
        $this->assertNotEmpty($extra);
    }

    public function testGetVersions()
    {
        $versions = Composer::getVersions();
        $this->assertIsArray($versions);
    }

    public function testGetScripts()
    {
        $scripts = Composer::getScripts();
        $this->assertIsArray($scripts);
    }
}
