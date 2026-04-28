<?php

declare(strict_types=1);

namespace Hypervel\Tests\Watcher;

use Hypervel\Tests\TestCase;
use Hypervel\Watcher\WatchPath;
use Hypervel\Watcher\WatchPathType;

class WatchPathTest extends TestCase
{
    public function testDirectoryWatchPath()
    {
        $path = new WatchPath('app', WatchPathType::Directory);

        $this->assertSame('app', $path->path);
        $this->assertSame(WatchPathType::Directory, $path->type);
        $this->assertNull($path->pattern);
    }

    public function testFileWatchPath()
    {
        $path = new WatchPath('.env', WatchPathType::File);

        $this->assertSame('.env', $path->path);
        $this->assertSame(WatchPathType::File, $path->type);
        $this->assertNull($path->pattern);
    }

    public function testDirectoryWithGlobPattern()
    {
        $path = new WatchPath('config', WatchPathType::Directory, 'config/**/*.php');

        $this->assertSame('config', $path->path);
        $this->assertSame(WatchPathType::Directory, $path->type);
        $this->assertSame('config/**/*.php', $path->pattern);
    }

    public function testMatchesBareDirectory()
    {
        $path = new WatchPath('app', WatchPathType::Directory);

        $this->assertTrue($path->matches('app/Foo.php'));
        $this->assertTrue($path->matches('app/Sub/Bar.php'));
        $this->assertFalse($path->matches('bootstrap/app.php'));
        $this->assertFalse($path->matches('app'));
    }

    public function testMatchesGlobPattern()
    {
        $path = new WatchPath('config', WatchPathType::Directory, 'config/**/*.php');

        $this->assertTrue($path->matches('config/app.php'));
        $this->assertTrue($path->matches('config/sub/db.php'));
        $this->assertFalse($path->matches('config/data.json'));
        $this->assertFalse($path->matches('other/app.php'));
    }

    public function testMatchesMiddleWildcard()
    {
        $path = new WatchPath('app', WatchPathType::Directory, 'app/*/Actions/*.php');

        $this->assertTrue($path->matches('app/Http/Actions/Create.php'));
        $this->assertFalse($path->matches('app/Http/Controllers/Show.php'));
        // Single * does not cross directory boundaries
        $this->assertFalse($path->matches('app/Http/Sub/Actions/Create.php'));
    }

    public function testMatchesQuestionMarkPattern()
    {
        $path = new WatchPath('routes', WatchPathType::Directory, 'routes/?.php');

        $this->assertTrue($path->matches('routes/a.php'));
        $this->assertFalse($path->matches('routes/ab.php'));
        $this->assertFalse($path->matches('routes/web.php'));
    }

    public function testMatchesBracePattern()
    {
        $path = new WatchPath('config', WatchPathType::Directory, 'config/{app,queue}.php');

        $this->assertTrue($path->matches('config/app.php'));
        $this->assertTrue($path->matches('config/queue.php'));
        $this->assertFalse($path->matches('config/cache.php'));
    }

    public function testMatchesBracketPattern()
    {
        $path = new WatchPath('lang', WatchPathType::Directory, 'lang/[a-z][a-z].php');

        $this->assertTrue($path->matches('lang/en.php'));
        $this->assertTrue($path->matches('lang/fr.php'));
        $this->assertFalse($path->matches('lang/eng.php'));
    }

    public function testMatchesFile()
    {
        $path = new WatchPath('.env', WatchPathType::File);

        $this->assertTrue($path->matches('.env'));
        $this->assertFalse($path->matches('.env.local'));
        $this->assertFalse($path->matches('app/.env'));
    }
}
