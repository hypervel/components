<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

final readonly class ProcessIdentity
{
    public const string EVENT = 'event';

    public const string TASK = 'task';

    public const string PROCESS = 'process';

    public const string CLI = 'cli';

    /**
     * Create a producing-process identity.
     */
    private function __construct(
        public string $type,
        public ?int $workerId = null,
        public ?string $processClass = null,
        public ?string $processName = null,
        public ?int $processIndex = null,
    ) {
    }

    /**
     * Create an event-worker identity.
     */
    public static function eventWorker(int $workerId): self
    {
        return new self(self::EVENT, workerId: $workerId);
    }

    /**
     * Create a task-worker identity.
     */
    public static function taskWorker(int $workerId): self
    {
        return new self(self::TASK, workerId: $workerId);
    }

    /**
     * Create a custom server-process identity.
     */
    public static function serverProcess(string $class, string $name, int $index): self
    {
        return new self(
            self::PROCESS,
            processClass: $class,
            processName: $name,
            processIndex: $index,
        );
    }

    /**
     * Create a standalone CLI identity.
     */
    public static function cli(): self
    {
        return new self(self::CLI, workerId: 0);
    }

    /**
     * Return the stable identity segment within this application instance.
     */
    public function stableId(): string
    {
        return match ($this->type) {
            self::PROCESS => "{$this->processName}.{$this->processIndex}",
            default => (string) $this->workerId,
        };
    }

    /**
     * Return Hypervel-specific resource attributes.
     *
     * @return array<string, int|string>
     */
    public function resourceAttributes(): array
    {
        $attributes = ['hypervel.worker.type' => $this->type];

        if ($this->workerId !== null) {
            $attributes['hypervel.worker.id'] = $this->workerId;
        }

        if ($this->type === self::PROCESS) {
            $attributes['hypervel.process.class'] = $this->processClass;
            $attributes['hypervel.process.name'] = $this->processName;
            $attributes['hypervel.process.index'] = $this->processIndex;
        }

        return $attributes;
    }
}
