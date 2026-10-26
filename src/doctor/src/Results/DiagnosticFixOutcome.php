<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Results;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\DiagnosticSource;
use JsonSerializable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class DiagnosticFixOutcome implements Arrayable, JsonSerializable
{
    /**
     * Create a new diagnostic fix outcome instance.
     */
    public function __construct(
        public Diagnostic $diagnostic,
        public FixResult $result,
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
     * Get the array representation of the fix outcome.
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
            'summary' => $this->result->summary,
            'details' => $this->result->details,
        ];
    }

    /**
     * Get the JSON representation of the fix outcome.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
