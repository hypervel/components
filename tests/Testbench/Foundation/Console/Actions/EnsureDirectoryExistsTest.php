<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\EnsureDirectoryExists;
use Hypervel\Tests\Console\Fixtures\ComponentsFactory;
use Hypervel\Tests\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

class EnsureDirectoryExistsTest extends TestCase
{
    use VerifiesDoubles;

    #[Test]
    public function itCanEnsureDirectoryExists(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('isDirectory')->with('a')->returns(false);
        $filesystem->expects('ensureDirectoryExists')->with('a', 493, true);
        $filesystem->expects('copy')->with(Argument::type('string'), join_paths('a', '.gitkeep'))->returns(true);
        $filesystem->expects('isDirectory')->with('b')->returns(true);
        $filesystem->expects('ensureDirectoryExists')->never()->with('b', 493, true);
        $filesystem->expects('copy')->never()->with(Argument::type('string'), join_paths('b', '.gitkeep'));
        $filesystem->expects('isDirectory')->with(join_paths('c', 'd'))->returns(false);
        $filesystem->expects('ensureDirectoryExists')->with(join_paths('c', 'd'), 493, true);
        $filesystem->expects('copy')->with(Argument::type('string'), join_paths('c', 'd', '.gitkeep'))->returns(true);

        $components->expects('task')->with('Prepare [a] directory');
        $components->expects('twoColumnDetail')->with('Directory [b] already exists', '<fg=yellow;options=bold>SKIPPED</>');
        $components->expects('task')->with(sprintf('Prepare [%s] directory', join_paths('c', 'd')));

        (new EnsureDirectoryExists(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', join_paths('c', 'd')]);
    }

    #[Test]
    public function itCanPretendToEnsureDirectoryExists(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('isDirectory')->with('a')->returns(false);
        $filesystem->expects('isDirectory')->with('b')->returns(true);
        $filesystem->expects('ensureDirectoryExists')->never();
        $filesystem->expects('copy')->never();

        $components->expects('task')->with('Prepare [a] directory');
        $components->expects('twoColumnDetail')->with('Directory [b] already exists', '<fg=yellow;options=bold>SKIPPED</>');

        (new EnsureDirectoryExists(
            filesystem: $filesystem,
            components: $components,
            pretending: true,
        ))->handle(['a', 'b']);
    }

    #[Test]
    public function itFailsWhenThePlaceholderCannotBeCopied(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();
        $filesystem->expects('isDirectory')->with('a')->returns(false);
        $filesystem->expects('ensureDirectoryExists')->with('a', 493, true);
        $filesystem->expects('copy')
            ->with(Argument::type('string'), join_paths('a', '.gitkeep'))
            ->returns(false);
        $components->expects('task')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to create placeholder file [a/.gitkeep].');

        (new EnsureDirectoryExists(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a']);
    }
}
