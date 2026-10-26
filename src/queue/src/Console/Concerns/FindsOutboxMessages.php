<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console\Concerns;

use RuntimeException;

trait FindsOutboxMessages
{
    /**
     * Find one message without guessing when its UUID exists on multiple sources.
     *
     * @return null|array{database: string, message: object}
     */
    protected function findOutboxMessage(string $uuid, ?string $database): ?array
    {
        $found = null;

        foreach ($database === null ? $this->repository->getDatabases() : [$database] as $source) {
            if (($message = $this->repository->find($source, $uuid)) === null) {
                continue;
            }

            if ($found !== null) {
                throw new RuntimeException("Outbox message [{$uuid}] exists on multiple databases. Select one using --database.");
            }

            $found = ['database' => $source, 'message' => $message];
        }

        return $found;
    }
}
