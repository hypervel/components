<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Http;

use Hypervel\Telescope\Telescope;
use Hypervel\Tests\Telescope\FeatureTestCase;

use function Hypervel\Coroutine\parallel;

class CspTest extends FeatureTestCase
{
    public function testDashboardAssetsHaveNoNonceByDefault(): void
    {
        $this->assertStringNotContainsString(' nonce=', (string) Telescope::css());
        $this->assertStringNotContainsString(' nonce=', (string) Telescope::js());
    }

    public function testCspNonceIsAppliedToEveryDashboardAssetTag(): void
    {
        $this->assertInstanceOf(Telescope::class, Telescope::cspNonce('dashboard-nonce'));

        $css = (string) Telescope::css();
        $js = (string) Telescope::js();

        $this->assertSame(2, substr_count($css, '<style nonce="dashboard-nonce">'));
        $this->assertStringContainsString('<script type="module" nonce="dashboard-nonce">', $js);
    }

    public function testCspNonceIsIsolatedBetweenConcurrentCoroutines(): void
    {
        [$first, $second] = parallel([
            function (): array {
                Telescope::cspNonce('first-nonce');
                usleep(5000);

                return [(string) Telescope::css(), (string) Telescope::js()];
            },
            function (): array {
                Telescope::cspNonce('second-nonce');
                usleep(5000);

                return [(string) Telescope::css(), (string) Telescope::js()];
            },
        ]);

        $this->assertStringContainsString(' nonce="first-nonce"', $first[0]);
        $this->assertStringContainsString(' nonce="first-nonce"', $first[1]);
        $this->assertStringNotContainsString('second-nonce', $first[0] . $first[1]);

        $this->assertStringContainsString(' nonce="second-nonce"', $second[0]);
        $this->assertStringContainsString(' nonce="second-nonce"', $second[1]);
        $this->assertStringNotContainsString('first-nonce', $second[0] . $second[1]);
    }
}
