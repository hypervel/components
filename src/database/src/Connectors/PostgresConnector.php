<?php

declare(strict_types=1);

namespace Hypervel\Database\Connectors;

use Hypervel\Database\Concerns\ParsesSearchPath;
use PDO;

class PostgresConnector extends Connector implements ConnectorInterface
{
    use ParsesSearchPath;

    /**
     * The default PDO connection options.
     */
    protected array $options = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    /**
     * Establish a database connection.
     *
     * Startup parameters (search_path, timezone, isolation level, synchronous
     * commit) are baked into the DSN via libpq's "options" parameter rather
     * than issued as post-connect SET statements. This keeps them intact on
     * pooled connections (PgBouncer transaction pooling, pgdog, etc.) where
     * a SET applied to one backend is lost when the pooler hands the next
     * query to a different backend.
     */
    public function connect(array $config): PDO
    {
        return $this->createConnection(
            $this->getDsn($config),
            $config,
            $this->getOptions($config)
        );
    }

    /**
     * Create a DSN string from a configuration.
     */
    protected function getDsn(array $config): string
    {
        // First we will create the basic DSN setup as well as the port if it is in
        // in the configuration options. This will give us the basic DSN we will
        // need to establish the PDO connections and return them back for use.
        extract($config, EXTR_SKIP);

        $host = isset($host) ? "host={$host};" : '';

        // Sometimes - users may need to connect to a database that has a different
        // name than the database used for "information_schema" queries. This is
        // typically the case if using "pgbouncer" type software when pooling.
        $database = $connect_via_database ?? $database ?? null;
        $port = $connect_via_port ?? $port ?? null;

        $dsn = "pgsql:{$host}dbname='{$database}'";

        // If a port was specified, we will add it to this Postgres DSN connections
        // format. Once we have done that we are ready to return this connection
        // string back out for usage, as this has been fully constructed here.
        if (! is_null($port)) {
            $dsn .= ";port={$port}";
        }

        if (isset($charset)) {
            $dsn .= ";client_encoding='{$charset}'";
        }

        // Postgres allows an application_name to be set by the user and this name is
        // used to when monitoring the application with pg_stat_activity. So we'll
        // determine if the option has been specified and run a statement if so.
        if (isset($application_name)) {
            $dsn .= ";application_name='" . str_replace("'", "\\'", $application_name) . "'";
        }

        $startupOptions = $this->buildStartupOptions($config);

        if ($startupOptions !== null) {
            $dsn .= ";options='{$startupOptions}'";
        }

        return $this->addSslOptions($dsn, $config);
    }

    /**
     * Build the libpq "options" parameter value from startup settings.
     *
     * Returns null when no startup settings are configured. Each setting is
     * expressed as a "-c key=value" flag, space-separated. Values containing
     * spaces (e.g. "read committed") are backslash-escaped so libpq preserves
     * them as a single token when it splits the options string on whitespace.
     */
    protected function buildStartupOptions(array $config): ?string
    {
        $parts = [];

        if (isset($config['search_path']) || isset($config['schema'])) {
            $searchPath = $this->quoteSearchPath(
                $this->parseSearchPath($config['search_path'] ?? $config['schema'])
            );

            $parts[] = '-c search_path=' . $this->escapeStartupOptionValue($searchPath);
        }

        if (isset($config['timezone'])) {
            $parts[] = '-c TimeZone=' . $this->escapeStartupOptionValue((string) $config['timezone']);
        }

        if (isset($config['isolation_level'])) {
            $parts[] = '-c default_transaction_isolation=' . $this->escapeStartupOptionValue((string) $config['isolation_level']);
        }

        if (isset($config['synchronous_commit'])) {
            $parts[] = '-c synchronous_commit=' . $this->escapeStartupOptionValue((string) $config['synchronous_commit']);
        }

        return $parts !== [] ? implode(' ', $parts) : null;
    }

    /**
     * Escape a startup option value for use inside libpq's options parameter.
     *
     * libpq splits the options string on unescaped whitespace, so spaces that
     * belong to a single value (like the space in "read committed") must be
     * backslash-escaped to stay part of that value rather than being treated
     * as a token separator.
     */
    protected function escapeStartupOptionValue(string $value): string
    {
        return str_replace(' ', '\ ', $value);
    }

    /**
     * Add the SSL options to the DSN.
     */
    protected function addSslOptions(string $dsn, array $config): string
    {
        foreach (['sslmode', 'sslcert', 'sslkey', 'sslrootcert'] as $option) {
            if (isset($config[$option])) {
                $dsn .= ";{$option}={$config[$option]}";
            }
        }

        return $dsn;
    }

    /**
     * Format the search path as a quoted identifier list.
     */
    protected function quoteSearchPath(array $searchPath): string
    {
        return count($searchPath) === 1 ? '"' . $searchPath[0] . '"' : '"' . implode('", "', $searchPath) . '"';
    }
}
