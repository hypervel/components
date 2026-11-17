<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Composer\Autoload\ClassLoader;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Support\Composer;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Output\BufferedOutput;

class ComposerAutoloadIsAuthoritativeTest extends TestCase
{
    #[TestWith(['local', false, 'skip', 0])]
    #[TestWith(['production', false, 'fail', 1])]
    #[TestWith(['production', true, 'pass', 0])]
    public function testDoctorChecksTheProductionAutoloader(
        string $environment,
        bool $authoritative,
        string $status,
        int $exitCode,
    ): void {
        $this->app->detectEnvironment(fn (): string => $environment);
        $loader = new ClassLoader;
        $loader->setClassMapAuthoritative($authoritative);
        Composer::setLoader($loader);

        $output = new BufferedOutput;
        $actualExitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'ComposerAutoloadIsAuthoritative',
            '--format' => 'json',
        ], $output);
        $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($exitCode, $actualExitCode);
        $this->assertCount(1, $report['diagnostics']);
        $this->assertSame($status, $report['diagnostics'][0]['status']);

        if ($status === 'fail') {
            $this->assertStringContainsString('composer install --no-dev --classmap-authoritative', $report['diagnostics'][0]['remediation']);
        }
    }
}
