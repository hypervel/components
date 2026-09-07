<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Fixtures;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;

class CapturingAlwaysOffSampler implements SamplerInterface
{
    /** @var list<array{name: string, kind: int, attributes: array<string, mixed>}> */
    public array $samples = [];

    /**
     * Capture one sampling request and drop its span.
     */
    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        $this->samples[] = [
            'name' => $spanName,
            'kind' => $spanKind,
            'attributes' => $attributes->toArray(),
        ];

        return new SamplingResult(
            SamplingResult::DROP,
            traceState: Span::fromContext($parentContext)->getContext()->getTraceState(),
        );
    }

    /**
     * Return the sampler description.
     */
    public function getDescription(): string
    {
        return 'CapturingAlwaysOffSampler';
    }
}
