<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit;

use Hypervel\Filesystem\Filesystem;
use Hypervel\PHPStan\Properties\DirectoryResolver;
use Hypervel\Testing\ParallelTesting;
use PHPStan\File\FileHelper;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\Test;

use function mkdir;
use function touch;

class DirectoryResolverTest extends PHPStanTestCase
{
    private FileHelper $fileHelper;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileHelper = self::getContainer()->getByType(FileHelper::class);
        $this->root = ParallelTesting::tempDir('PHPStanDirectoryResolverTest');

        (new Filesystem)->deleteDirectory($this->root);
        mkdir($this->root);
        mkdir($this->root . '/a');
        mkdir($this->root . '/b');
        mkdir($this->root . '/[literal]');
        touch($this->root . '/c.php');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function itKeepsAPathWithoutGlobCharactersEvenIfItDoesNotExist(): void
    {
        self::assertSame(
            [$this->root . '/a', $this->root . '/missing'],
            DirectoryResolver::resolve([$this->root . '/a', $this->root . '/missing'], $this->fileHelper),
        );
    }

    #[Test]
    public function itExpandsAGlobToTheDirectoriesItMatches(): void
    {
        self::assertSame(
            [$this->root . '/[literal]', $this->root . '/a', $this->root . '/b'],
            DirectoryResolver::resolve([$this->root . '/*'], $this->fileHelper),
        );
    }

    #[Test]
    public function itDropsAGlobThatMatchesNoDirectory(): void
    {
        self::assertSame([], DirectoryResolver::resolve([$this->root . '/missing-*', $this->root . '/c.*'], $this->fileHelper));
    }

    #[Test]
    public function itTakesAnExistingDirectoryWithGlobCharactersLiterally(): void
    {
        self::assertSame(
            [$this->root . '/[literal]'],
            DirectoryResolver::resolve([$this->root . '/[literal]'], $this->fileHelper),
        );
    }

    #[Test]
    public function itListsADirectoryOnce(): void
    {
        self::assertSame(
            [$this->root . '/a', $this->root . '/b'],
            DirectoryResolver::resolve([$this->root . '/a', $this->root . '/[ab]', $this->root . '/a/'], $this->fileHelper),
        );
    }
}
