<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\DoctorServiceProvider;
use Hypervel\Testbench\TestCase as Testbench;
use Laravel\AgentDetector\AgentDetector;

// REMOVED: Feature/Diagnostics/ApplicationTimezoneIsValidTest.php; Hypervel rejects invalid timezones during boot, before Doctor can run.

abstract class TestCase extends Testbench
{
    /**
     * @var array<string, false|string>
     */
    private array $agentEnvironment = [];

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetAgentEnvironment();
    }

    /**
     * Restore process environment values after the test.
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->agentEnvironment as $variable => $value) {
                $value === false ? putenv($variable) : putenv("{$variable}={$value}");
            }
        }
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [
            DoctorServiceProvider::class,
        ];
    }

    /**
     * Get the autoloader used by independently booted Artisan processes.
     */
    protected function autoloadPath(): string
    {
        return dirname(__DIR__, 2) . '/vendor/autoload.php';
    }

    /**
     * Clear agent environment variables so output format detection stays
     * deterministic when the suite itself runs inside an AI agent.
     */
    protected function forgetAgentEnvironment(): void
    {
        foreach (['AI_AGENT', ...array_keys(AgentDetector::AGENT_ENV_VARS)] as $variable) {
            $this->agentEnvironment[$variable] = getenv($variable);
            putenv($variable);
        }
    }
}
