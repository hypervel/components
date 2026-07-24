<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

class DevCommand
{
    /**
     * The priority level for default commands that are registered by the framework.
     */
    public const int PRIORITY_DEFAULT = 0;

    /**
     * The priority level for commands that are registered by packages in the vendor directory.
     */
    public const int PRIORITY_VENDOR = 1;

    /**
     * The priority level for commands that are registered by the user in their application.
     */
    public const int PRIORITY_USERLAND = 2;

    /**
     * Color of the command when output to the console.
     */
    protected ?string $color = null;

    /**
     * Create a new DevCommand instance.
     *
     * @param array{file?: string, line?: int, class?: string, function?: string} $source
     * @param self::PRIORITY_DEFAULT|self::PRIORITY_USERLAND|self::PRIORITY_VENDOR $priority
     */
    public function __construct(
        protected string $command,
        protected array $source,
        protected ?string $name = null,
        protected int $priority = self::PRIORITY_USERLAND,
    ) {
        $this->name ??= self::nameFromCommand($command);
    }

    /**
     * Derive the name from a command string.
     */
    public static function nameFromCommand(string $command): string
    {
        return strstr($command, ' ', true) ?: $command;
    }

    /**
     * Get the command name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the command priority.
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * Set the command color.
     */
    public function color(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Set the command color to blue.
     */
    public function blue(): self
    {
        return $this->color(DevCommandColor::Blue->value);
    }

    /**
     * Set the command color to purple.
     */
    public function purple(): self
    {
        return $this->color(DevCommandColor::Purple->value);
    }

    /**
     * Set the command color to pink.
     */
    public function pink(): self
    {
        return $this->color(DevCommandColor::Pink->value);
    }

    /**
     * Set the command color to orange.
     */
    public function orange(): self
    {
        return $this->color(DevCommandColor::Orange->value);
    }

    /**
     * Set the command color to green.
     */
    public function green(): self
    {
        return $this->color(DevCommandColor::Green->value);
    }

    /**
     * Set the command color to yellow.
     */
    public function yellow(): self
    {
        return $this->color(DevCommandColor::Yellow->value);
    }

    /**
     * Get the command as an array.
     *
     * @return array{command: string, name: string, color: null|string, source: array{file?: string, line?: int, class?: string, function?: string}, priority: int}
     */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'name' => $this->name,
            'color' => $this->color,
            'source' => $this->source,
            'priority' => $this->priority,
        ];
    }
}
