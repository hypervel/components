<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Integration;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Sentry\State\Scope;
use Sentry\Tracing\Span;

use function Sentry\configureScope;

class IntegrationMetaTagTest extends TestCase
{
    use VerifiesDoubles;

    private const string DANGEROUS_PAYLOAD = '</meta><script>alert("owned")</script>';

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        configureScope(static function (Scope $scope): void {
            $scope->setSpan(null);
        });

        parent::tearDown();
    }

    public function testSentryTracingMetaEscapesDangerousTraceparentContent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
        ]);

        $dangerousTraceparent = self::DANGEROUS_PAYLOAD;

        $this->setDangerousSpanValues($dangerousTraceparent, 'safe-baggage');

        $metaTag = Integration::sentryTracingMeta();
        $expected = sprintf(
            '<meta name="sentry-trace" content="%s"/>',
            htmlspecialchars($dangerousTraceparent, ENT_QUOTES, 'UTF-8')
        );

        $this->assertSame($expected, $metaTag);
        $this->assertStringContainsString('&lt;script&gt;', $metaTag);
        $this->assertStringNotContainsString('<script>', $metaTag);
    }

    public function testSentryBaggageMetaEscapesDangerousBaggageContent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
        ]);

        $dangerousBaggage = self::DANGEROUS_PAYLOAD;

        $this->setDangerousSpanValues('safe-traceparent', $dangerousBaggage);

        $metaTag = Integration::sentryBaggageMeta();
        $expected = sprintf(
            '<meta name="baggage" content="%s"/>',
            htmlspecialchars($dangerousBaggage, ENT_QUOTES, 'UTF-8')
        );

        $this->assertSame($expected, $metaTag);
        $this->assertStringContainsString('&lt;script&gt;', $metaTag);
        $this->assertStringNotContainsString('<script>', $metaTag);
    }

    public function testSentryTracingMetaReturnsAWellFormedMetaTag(): void
    {
        $meta = Integration::sentryTracingMeta();

        $this->assertStringStartsWith('<meta name="sentry-trace" content="', $meta);
        $this->assertStringEndsWith('"/>', $meta);
    }

    public function testSentryBaggageMetaReturnsAWellFormedMetaTag(): void
    {
        $meta = Integration::sentryBaggageMeta();

        $this->assertStringStartsWith('<meta name="baggage" content="', $meta);
        $this->assertStringEndsWith('"/>', $meta);
    }

    /**
     * Set a current span that returns the given trace values.
     */
    private function setDangerousSpanValues(string $traceparent, string $baggage): void
    {
        $span = Double::for(Span::class)->strict();
        $span->allows('toTraceparent')->returns($traceparent);
        $span->allows('toBaggage')->returns($baggage);

        configureScope(static function (Scope $scope) use ($span): void {
            $scope->setSpan($span);
        });
    }
}
