<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Sentry\Console\PublishCommand;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Sentry\SentryTestCase;
use ReflectionMethod;

class PublishCommandTest extends SentryTestCase
{
    public function testIsEnvKeySetTreatsRegexMetacharactersAsLiterals(): void
    {
        $command = new PublishCommand;

        $isEnvKeySetMethod = new ReflectionMethod($command, 'isEnvKeySet');
        $this->assertFalse((bool) $isEnvKeySetMethod->invoke(
            $command,
            'SENTRY.*KEY',
            "SENTRYAKEY=true\n"
        ));

        $this->assertTrue((bool) $isEnvKeySetMethod->invoke(
            $command,
            'SENTRY.*KEY',
            "SENTRY.*KEY=true\n"
        ));
    }

    public function testEnvKeyPatternEscapesRegexMetacharactersForReplacement(): void
    {
        $command = new PublishCommand;

        $getEnvKeyPatternMethod = new ReflectionMethod($command, 'getEnvKeyPattern');
        $pattern = $getEnvKeyPatternMethod->invoke($command, 'SENTRY.*KEY');

        $updatedContents = preg_replace(
            $pattern,
            "SENTRY.*KEY=new\n",
            "SENTRYAKEY=old\nSENTRY.*KEY=old\n"
        );

        $this->assertSame("SENTRYAKEY=old\nSENTRY.*KEY=new\n", $updatedContents);
    }

    public function testSetEnvValuesOverwritesExistingVariablesThroughTheSharedWriter(): void
    {
        $directory = ParallelTesting::tempDir('SentryPublishCommandTest');
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($directory);
        $filesystem->makeDirectory($directory);
        $filesystem->put("{$directory}/.env", "SENTRY_HYPERVEL_DSN=old\n");

        $originalEnvironmentPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath($directory);

        try {
            $command = new PublishCommand;
            $method = new ReflectionMethod($command, 'setEnvValues');

            $this->assertTrue($method->invoke($command, [
                'SENTRY_HYPERVEL_DSN' => 'https://public@sentry.test/1',
                'SENTRY_SEND_DEFAULT_PII' => 'true',
            ]));
            $this->assertSame(
                "SENTRY_HYPERVEL_DSN=\"https://public@sentry.test/1\"\nSENTRY_SEND_DEFAULT_PII=true\n",
                $filesystem->get("{$directory}/.env")
            );
        } finally {
            $this->app->useEnvironmentPath($originalEnvironmentPath);
            $filesystem->deleteDirectory($directory);
        }
    }
}
