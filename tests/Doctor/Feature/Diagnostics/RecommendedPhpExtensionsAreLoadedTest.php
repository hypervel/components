<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\RecommendedPhpExtensionsAreLoaded;
use Hypervel\Doctor\Support\ComposerJson;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

class RecommendedPhpExtensionsAreLoadedTest extends TestCase
{
    #[RequiresPhpExtension('Zend OPcache')]
    public function testRecognizesComposerNormalizedExtensionNames(): void
    {
        $composer = m::mock(ComposerJson::class);
        $composer->shouldReceive('suggestedExtensions')->andReturn(['zend-opcache']);

        $result = (new RecommendedPhpExtensionsAreLoaded($composer))->check();

        $this->assertSame('recommended-php-extensions-are-loaded.installed', $result->code);
    }

    public function testWarnsAboutMissingComposerSuggestedPhpExtensions(): void
    {
        $basePath = ParallelTesting::tempDir('DoctorRecommendedExtensions');
        File::deleteDirectory($basePath);
        File::makeDirectory($basePath, 0775, true);

        try {
            file_put_contents($basePath . '/composer.json', json_encode([
                'suggest' => [
                    'ext-hypervel_doctor_recommended_extension' => 'Used by optional Doctor tests.',
                ],
            ], JSON_THROW_ON_ERROR));

            $this->app->setBasePath($basePath);

            $result = $this->app->make(RecommendedPhpExtensionsAreLoaded::class)->check();

            $this->assertSame('warn', $result->status->value);
            $this->assertStringContainsString('ext-hypervel_doctor_recommended_extension', $result->details);
        } finally {
            File::deleteDirectory($basePath);
        }
    }
}
