<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\EnvironmentDetector;
use Hypervel\Tests\TestCase;

class FoundationEnvironmentDetectorTest extends TestCase
{
    public function testClosureCanBeUsedForCustomEnvironmentDetection(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        });
        $this->assertSame('foobar', $result);
    }

    public function testConsoleEnvironmentDetection(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        }, ['--env=local']);
        $this->assertSame('local', $result);
    }

    public function testConsoleEnvironmentDetectionSeparatedWithSpace(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        }, ['--env', 'local']);
        $this->assertSame('local', $result);
    }

    public function testConsoleEnvironmentDetectionWithNoValue(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        }, ['--env']);
        $this->assertSame('foobar', $result);
    }

    public function testConsoleEnvironmentDetectionDoesNotUseArgumentThatStartsWithEnv(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        }, ['--envelope=mail']);
        $this->assertSame('foobar', $result);
    }

    public function testConsoleEnvironmentDetectionDoesNotUseArgumentThatStartsWithEnvSeparatedWithSpace(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        }, ['--envelope', 'mail']);
        $this->assertSame('foobar', $result);
    }

    public function testConsoleEnvironmentDetectionDoesNotUseArgumentThatStartsWithEnvWithNoValue(): void
    {
        $env = new EnvironmentDetector;

        $result = $env->detect(function () {
            return 'foobar';
        }, ['--envelope']);
        $this->assertSame('foobar', $result);
    }
}
