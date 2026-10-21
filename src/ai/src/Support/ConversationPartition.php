<?php

declare(strict_types=1);

namespace Hypervel\Ai\Support;

use Hypervel\Ai\AiManager;
use Hypervel\Container\Container;

readonly class ConversationPartition
{
    /**
     * Create a resolved conversation partition.
     */
    public function __construct(
        public string $column,
        public int|string $value,
    ) {
    }

    /**
     * Resolve the configured column and the current operation's partition together.
     */
    public static function current(): ?self
    {
        $manager = Container::getInstance()->make(AiManager::class);
        $column = $manager->conversationPartitionColumn();

        if ($column === null) {
            return null;
        }

        return new self($column, $manager->conversationPartition());
    }

    /**
     * Determine whether a value represents this partition.
     */
    public function matches(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && (string) $value === (string) $this->value;
    }
}
