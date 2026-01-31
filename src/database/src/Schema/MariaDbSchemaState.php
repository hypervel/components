<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Override;

class MariaDbSchemaState extends MySqlSchemaState
{
    /**
     * Load the given schema file into the database.
     */
    #[Override]
    public function load(string $path): void
    {
        $command = 'mariadb ' . $this->connectionString() . ' --database="${:LARAVEL_LOAD_DATABASE}" < "${:LARAVEL_LOAD_PATH}"';

        $process = $this->makeProcess($command)->setTimeout(null);

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'LARAVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the base dump command arguments for MariaDB as a string.
     */
    #[Override]
    protected function baseDumpCommand(): string
    {
        $command = 'mariadb-dump ' . $this->connectionString() . ' --no-tablespaces --skip-add-locks --skip-comments --skip-set-charset --tz-utc';

        return $command . ' "${:LARAVEL_LOAD_DATABASE}"';
    }
}
