<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Fixtures\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;

class LinkedDiagnostic extends Diagnostic
{
    public string $name = 'Testing diagnostic has links';

    public string $group = 'testing';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'warning' => Message::make(
                summary: 'The linked diagnostic warned.',
                remediation: 'Follow the linked documentation.',
            )
                ->link(Link::docs('queues', 'connections-vs-queues'))
                ->link(Link::to('Related {topic} guide', 'https://example.com/{topic}')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        return $this->warn('warning', ['topic' => 'links'])
            ->withDetails('Detailed link context.');
    }
}
