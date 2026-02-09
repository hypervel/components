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
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Composer::setBasePath(__DIR__ . '/fixtures/composer');
    }

    protected function tearDown(): void
    {
        Composer::setBasePath(null);

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
