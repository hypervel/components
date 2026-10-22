<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Database\SQLiteDatabase;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Support\Facades\File;

class SqliteDatabaseExists extends Diagnostic implements Fixable
{
    public string $name = 'SQLite database exists';

    public string $group = 'database';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-sqlite' => 'The default database connection is not SQLite.',
            'not-file' => 'The SQLite connection does not use a database file.',
            'uri' => 'The SQLite connection uses a URI; the connector manages its database path and creation mode.',
            'exists' => 'The SQLite database file exists.',
            'missing' => Message::make(
                summary: 'The SQLite database file does not exist.',
                remediation: 'Create the SQLite database file with `touch {database}`.',
                confirmation: 'Create the SQLite database file?',
            )->link(Link::docs('database', 'configuration')),
            'already-exists' => 'The SQLite database file already exists.',
            'creation-failed' => 'The SQLite database file could not be created.',
            'created' => 'The SQLite database file was created.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $connection = Configured::string('database.default');
        $configuration = $connection === null ? null : config("database.connections.{$connection}");

        if (! is_array($configuration)) {
            return $this->skip('not-sqlite');
        }

        $configuration = app('db.factory')->parseConfig($configuration, $connection);

        if (($configuration['driver'] ?? null) !== 'sqlite') {
            return $this->skip('not-sqlite');
        }

        $database = $configuration['database'] ?? null;

        if (! is_string($database) || $database === '' || SQLiteDatabase::isInMemory($database)) {
            return $this->skip('not-file');
        }

        if (SQLiteDatabase::isUri($database)) {
            return $this->skip('uri');
        }

        if (! $this->isAbsolutePath($database)) {
            $database = realpath($database) ?: base_path($database);
        }

        if (is_file($database)) {
            return $this->pass('exists')
                ->withContext('database', $database);
        }

        return $this->fail('missing', ['database' => $database])
            ->fixable()
            ->withContext('database', $database);
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        /** @var string $database */
        $database = $result->context['database'];

        if (is_file($database)) {
            return $this->fixed('already-exists');
        }

        File::ensureDirectoryExists(dirname($database));

        $handle = @fopen($database, 'x');

        if ($handle === false) {
            return is_file($database)
                ? $this->fixed('already-exists')
                : $this->fixFailed('creation-failed');
        }

        fclose($handle);

        return $this->fixed('created');
    }

    /**
     * Determine whether the database path is absolute.
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1;
    }
}
