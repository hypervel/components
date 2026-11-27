<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Doctor\Diagnostics\OpcacheIsEnabledForCli;
use Hypervel\Doctor\Results\Link;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;

class OpcacheIsEnabledForCliTest extends TestCase
{
    public function testSkipsInLocalEnvironment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('doctor', [
            '--only' => 'OpcacheIsEnabledForCli',
            '--format' => 'json',
        ], $output);
        $report = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $report['diagnostics']);
        $this->assertSame('skip', $report['diagnostics'][0]['status']);
        $this->assertSame('opcache-is-enabled-for-cli.local', $report['diagnostics'][0]['code']);
    }

    #[DataProvider('productionStates')]
    public function testReportsProductionOpcacheStates(
        bool $loaded,
        bool $enable,
        bool $enableCli,
        string $status,
        string $summary,
        ?string $details,
    ): void {
        $this->app->detectEnvironment(fn (): string => 'production');

        $diagnostic = new class($loaded, $enable, $enableCli) extends OpcacheIsEnabledForCli {
            /**
             * Create a diagnostic with controlled OPcache runtime state.
             */
            public function __construct(
                private bool $loaded,
                private bool $enable,
                private bool $enableCli,
            ) {
                parent::__construct();
            }

            /**
             * Supply the extension state independently of the test runner's PHP build.
             */
            protected function opcacheLoaded(): bool
            {
                return $this->loaded;
            }

            /**
             * Supply INI flags independently of the test runner's PHP configuration.
             */
            protected function iniFlag(string $option): bool
            {
                return match ($option) {
                    'opcache.enable' => $this->enable,
                    'opcache.enable_cli' => $this->enableCli,
                    default => false,
                };
            }
        };

        $result = $diagnostic->check();

        $this->assertSame($status, $result->status->value);
        $this->assertSame($summary, $result->summary);
        $this->assertSame($details, $result->details);

        if ($status === 'fail') {
            $this->assertNotNull($result->remediation);
            $this->assertStringContainsString('opcache.enable=1', $result->remediation);
            $this->assertStringContainsString('opcache.enable_cli=1', $result->remediation);
            $this->assertEquals([Link::docs('deployment', 'php-configuration')], $result->links);
        } else {
            $this->assertNull($result->remediation);
            $this->assertSame([], $result->links);
        }
    }

    /**
     * Provide production OPcache extension and INI states.
     *
     * @return array<string, array{bool, bool, bool, string, string, ?string}>
     */
    public static function productionStates(): array
    {
        return [
            'extension not loaded' => [
                false,
                false,
                false,
                'fail',
                'The Zend OPcache extension is not loaded in production.',
                null,
            ],
            'enable disabled while enable_cli enabled' => [
                true,
                false,
                true,
                'fail',
                'OPcache is not enabled for the PHP CLI in production.',
                "- opcache.enable = 0\n- opcache.enable_cli = 1",
            ],
            'enable enabled while enable_cli disabled' => [
                true,
                true,
                false,
                'fail',
                'OPcache is not enabled for the PHP CLI in production.',
                "- opcache.enable = 1\n- opcache.enable_cli = 0",
            ],
            'both directives enabled' => [
                true,
                true,
                true,
                'pass',
                'OPcache is enabled for the PHP CLI.',
                null,
            ],
        ];
    }
}
