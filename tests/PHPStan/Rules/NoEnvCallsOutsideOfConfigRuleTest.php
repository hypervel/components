<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoEnvCallsOutsideOfConfigRule;
use Hypervel\PHPStan\Support\ConfigSourceResolver;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

use function sprintf;

use const DIRECTORY_SEPARATOR;

/** @extends RuleTestCase<NoEnvCallsOutsideOfConfigRule> */
class NoEnvCallsOutsideOfConfigRuleTest extends RuleTestCase
{
    private const string MESSAGE = "Called 'env' outside of the config directory. The .env file is not loaded when the configuration is cached, so use 'config' instead.";

    private const string READER_MESSAGE = "Called '%s' outside of the config directory. The .env file is not loaded when the configuration is cached, so use 'config' instead.";

    private ?string $singleReflectionFile = null;

    private ?string $singleReflectionInsteadOfFile = null;

    // Upstream overrides the config path in setUp(); env-calls.neon sets configDirectories instead.

    protected function getRule(): Rule
    {
        return new NoEnvCallsOutsideOfConfigRule(
            self::getContainer()->getByType(ConfigSourceResolver::class),
            $this->getFileHelper(),
            $this->createReflectionProvider(),
            $this->singleReflectionFile,
            $this->singleReflectionInsteadOfFile,
        );
    }

    private function setSingleReflectionFiles(?string $file, ?string $insteadOfFile): void
    {
        $this->singleReflectionFile = $file;
        $this->singleReflectionInsteadOfFile = $insteadOfFile;
    }

    #[Test]
    public function itDoesNotFailForEnvCallsInsideConfigDirectory(): void
    {
        $this->analyse([__DIR__ . '/data/config/env-calls.php'], []);
    }

    #[Test]
    public function itDoesNotFailForEnvCallsInsideGlobConfigDirectory(): void
    {
        $this->analyse([__DIR__ . '/data/module/foo/config/env-calls.php', __DIR__ . '/data/module/bar/config/env-calls.php'], []);
    }

    #[Test]
    public function itReportsEnvCallsOutsideOfConfigDirectory(): void
    {
        $this->analyse([__DIR__ . '/data/env-calls.php'], [
            [self::MESSAGE, 7],
            [self::MESSAGE, 8],
        ]);
    }

    #[Test]
    public function itReportsEveryEnvReaderOutsideOfConfigDirectory(): void
    {
        $this->analyse([__DIR__ . '/data/env-readers.php'], [
            [sprintf(self::READER_MESSAGE, 'env_array'), 9],
            [sprintf(self::READER_MESSAGE, 'env_or_fail'), 10],
            [sprintf(self::READER_MESSAGE, 'app_id'), 11],
            [sprintf(self::READER_MESSAGE, 'Hypervel\Support\Env::get'), 12],
            [sprintf(self::READER_MESSAGE, 'Hypervel\Support\Env::getArray'), 13],
            [sprintf(self::READER_MESSAGE, 'Hypervel\Support\Env::getOrFail'), 14],
            [sprintf(self::READER_MESSAGE, 'Hypervel\Support\Env::get'), 17],
            [sprintf(self::READER_MESSAGE, 'Hypervel\Testbench\Foundation\Env::get'), 18],
        ]);
    }

    #[Test]
    public function itDoesNotFailForEnvCallsInConfigPublishStubs(): void
    {
        $this->analyse([__DIR__ . '/../../../src/foundation/config-stubs/app.php'], []);
    }

    #[Test]
    public function itReportsEnvCallsInDirectoryNamedLikeConfigDirectory(): void
    {
        $this->analyse([__DIR__ . '/data/config-sibling/env-calls.php'], [
            [self::MESSAGE, 6],
        ]);
    }

    #[Test]
    public function itDoesNotFailForEnvCallsInPackageConfigFiles(): void
    {
        $this->analyse([
            __DIR__ . '/../Fixtures/Config/config/package.php',
            __DIR__ . '/../Fixtures/Config/config/published.php',
        ], []);
    }

    #[Test]
    public function itDoesNotReportTraitFunctionsThatHaveBeenOverridden(): void
    {
        $this->analyse([
            __DIR__ . '/data/EnvUsageClassOverride.php',
            __DIR__ . '/data/EnvUsageTrait.php',
        ], []);
    }

    #[Test]
    public function itReportsEnvCallsInTraitRatherThanClass(): void
    {
        $actualErrors = $this->gatherAnalyserErrors([
            __DIR__ . '/data/EnvUsageClass.php',
            __DIR__ . '/data/EnvUsageTrait.php',
        ]);

        $this->assertCount(2, $actualErrors);
        $this->assertSame(self::MESSAGE, $actualErrors[0]->getMessage());
        $this->assertSame(
            __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'EnvUsageTrait.php (in context of class Hypervel\Tests\PHPStan\Rules\Data\EnvUsageClass)',
            $actualErrors[0]->getFile(),
        );
        $this->assertSame(17, $actualErrors[0]->getLine());

        $this->assertSame(self::MESSAGE, $actualErrors[1]->getMessage());
        $this->assertSame(
            __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'EnvUsageTrait.php (in context of class Hypervel\Tests\PHPStan\Rules\Data\EnvUsageClass)',
            $actualErrors[1]->getFile(),
        );
        $this->assertSame(18, $actualErrors[1]->getLine());
    }

    #[Test]
    public function itDoesNotReportInEditorModeWhenInsteadOfPointsAtConfigFile(): void
    {
        $this->setSingleReflectionFiles(__DIR__ . '/data/env-calls.php', __DIR__ . '/data/config/env-calls.php');

        $this->analyse([__DIR__ . '/data/env-calls.php'], []);
    }

    #[Test]
    public function itReportsInEditorModeWhenInsteadOfIsOutsideConfig(): void
    {
        $this->setSingleReflectionFiles(__DIR__ . '/data/env-calls.php', __DIR__ . '/data/some-non-config-file.php');

        $this->analyse([__DIR__ . '/data/env-calls.php'], [
            [self::MESSAGE, 7],
            [self::MESSAGE, 8],
        ]);
    }

    #[Test]
    public function itUsesNormalLogicWhenAnalysedFileDiffersFromTmpFile(): void
    {
        $this->setSingleReflectionFiles('/some/other/buffer.php', __DIR__ . '/data/config/env-calls.php');

        $this->analyse([__DIR__ . '/data/env-calls.php'], [
            [self::MESSAGE, 7],
            [self::MESSAGE, 8],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/data/env-calls.neon',
        ];
    }
}
