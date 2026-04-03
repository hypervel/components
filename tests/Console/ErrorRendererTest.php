<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\ErrorRenderer;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 * @coversNothing
 */
class ErrorRendererTest extends TestCase
{
    public function testDefaultVerbosityIsNormal()
    {
        $output = new BufferedOutput();
        $renderer = new ErrorRenderer(new ArrayInput([]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_NORMAL, $output->getVerbosity());
    }

    public function testSilentFlagSetsVerbosityToSilent()
    {
        $output = new BufferedOutput();
        new ErrorRenderer(new ArrayInput(['--silent' => true]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_SILENT, $output->getVerbosity());
    }

    public function testQuietFlagSetsVerbosityToQuiet()
    {
        $output = new BufferedOutput();
        new ErrorRenderer(new ArrayInput(['--quiet' => true]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_QUIET, $output->getVerbosity());
    }

    public function testVerboseFlagSetsVerbosityToVerbose()
    {
        $output = new BufferedOutput();
        new ErrorRenderer(new ArrayInput(['--verbose' => true]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_VERBOSE, $output->getVerbosity());
    }

    public function testVeryVerboseFlagSetsVerbosityToVeryVerbose()
    {
        $output = new BufferedOutput();
        new ErrorRenderer(new ArrayInput(['--verbose' => 2]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_VERY_VERBOSE, $output->getVerbosity());
    }

    public function testDebugFlagSetsVerbosityToDebug()
    {
        $output = new BufferedOutput();
        new ErrorRenderer(new ArrayInput(['--verbose' => 3]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_DEBUG, $output->getVerbosity());
    }

    public function testSilentTakesPrecedenceOverQuiet()
    {
        $output = new BufferedOutput();
        new ErrorRenderer(new ArrayInput(['--silent' => true, '--quiet' => true]), $output);

        $this->assertSame(OutputInterface::VERBOSITY_SILENT, $output->getVerbosity());
    }

    public function testRenderProducesOutput()
    {
        $output = new BufferedOutput();
        $renderer = new ErrorRenderer(new ArrayInput([]), $output);

        $renderer->render(new RuntimeException('Test error message'));

        $this->assertNotEmpty($output->fetch());
    }
}
