<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Override;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MariaDbSchemaState extends MySqlSchemaState
{
    /**
     * Load the given schema file into the database.
     */
    #[Override]
    public function load(string $path): void
    {
        $versionInfo = $this->detectClientVersion();

        $command = 'mariadb ' . $this->connectionString($versionInfo) . ' --database="${:HYPERVEL_LOAD_DATABASE}" < "${:HYPERVEL_LOAD_PATH}"';

        $process = $this->makeProcess($command)->setTimeout(null);

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the base dump command arguments for MariaDB as a string.
     */
    #[Override]
    protected function baseDumpCommand(): string
    {
        $versionInfo = $this->detectClientVersion();

        $command = 'mariadb-dump ' . $this->connectionString($versionInfo) . ' --no-tablespaces --skip-add-locks --skip-comments --skip-set-charset --tz-utc';

        return $command . ' "${:HYPERVEL_LOAD_DATABASE}"';
    }

    /**
     * Detect the MariaDB client version.
     *
     * @return array{version: string, isMariaDb: bool}
     */
    protected function detectClientVersion(): array
    {
        // Minimum version of MariaDB that supports the mariadb command...
        $version = '10.5.2';

        try {
            $versionOutput = $this->makeProcess('mariadb --version')->mustRun()->getOutput();

            if (preg_match('/(\d+\.\d+\.\d+)/', $versionOutput, $matches)) {
                $version = $matches[1];
            }
        } catch (ProcessFailedException) {
        }

        return [
            'isMariaDb' => true,
            'version' => $version,
        ];
    }
}
