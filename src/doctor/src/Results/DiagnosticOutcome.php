<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\DiagnosticSource;
use Hypervel\Doctor\EnvironmentMode;
use JsonSerializable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class DiagnosticOutcome implements Arrayable, JsonSerializable
{
    /**
     * Create a new diagnostic outcome instance.
     */
    public function __construct(
        public Diagnostic $diagnostic,
        public DiagnosticResult $result,
    ) {
    }

    /**
     * Get the diagnostic's source metadata.
     */
    public function source(): DiagnosticSource
    {
        return DiagnosticSource::for($this->diagnostic);
    }

    /**
     * Determine whether this outcome may be fixed.
     */
    public function fixable(): bool
    {
        return $this->diagnostic instanceof Fixable
            && $this->result->status === Status::Fail
            && $this->result->fixableIn(EnvironmentMode::current());
    }

    /**
     * Determine whether the fix requires choosing one of the result's options.
     */
    public function fixRequiresOption(): bool
    {
        return $this->fixable() && $this->result->fixOptions !== [];
    }

    /**
     * Get the array representation of the outcome.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $source = $this->source();

        return [
            'class' => $this->diagnostic::class,
            'group' => $this->diagnostic->group,
            'name' => $this->diagnostic->name,
            'source' => [
                'label' => $source->label(),
                'package' => $source->package,
                'file' => $source->relativeFile,
                'application' => $source->application,
            ],
            'code' => $this->result->code,
            'status' => $this->result->status->value,
            'fixable' => $this->fixable(),
            'options' => $this->result->fixOptions,
            'summary' => $this->result->summary,
            'details' => $this->result->details,
            'remediation' => $this->result->remediation,
            'links' => $this->links(),
        ];
    }

    /**
     * Get the JSON representation of the outcome.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [...$this->toArray(), 'links' => (object) $this->links(agent: true)];
    }

    /**
     * Get the result's links keyed by label.
     *
     * @return array<string, string>
     */
    protected function links(bool $agent = false): array
    {
        return array_column(
            $this->result->links,
            $agent ? 'agentUrl' : 'url',
            'label',
        );
    }
}
