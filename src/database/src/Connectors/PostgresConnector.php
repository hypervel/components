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
     * Get the PDO options based on the configuration.
     */
    public function getOptions(array $config): array
    {
        $options = parent::getOptions($config);

        if (isset($config['connect_timeout'])
            && ! array_key_exists(PDO::ATTR_TIMEOUT, $config['options'] ?? [])) {
            $options[PDO::ATTR_TIMEOUT] = (int) ceil($config['connect_timeout']);
        }

        return $options;
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

        $dsn = "pgsql:{$host}dbname=" . $this->quoteConnectionValue((string) $database);

        // If a port was specified, we will add it to this Postgres DSN connections
        // format. Once we have done that we are ready to return this connection
        // string back out for usage, as this has been fully constructed here.
        if (! is_null($port)) {
            $dsn .= ";port={$port}";
        }

        if (isset($charset)) {
            $dsn .= ';client_encoding=' . $this->quoteConnectionValue($charset);
        }

        // Postgres allows an application_name to be set by the user and this name is
        // used when monitoring the application with pg_stat_activity. So we'll
        // determine if the option has been specified and add it to the DSN.
        if (isset($application_name)) {
            $dsn .= ';application_name=' . $this->quoteConnectionValue($application_name);
        }

        return $this->addServerOptions(
            $this->addKeepaliveOptions($this->addSslOptions($dsn, $config), $config),
            $config
        );
    }

    /**
     * Add the server options to the DSN.
     */
    protected function addServerOptions(string $dsn, array $config): string
    {
        $parts = [];

        foreach ($config['server_options'] ?? [] as $name => $value) {
            $parts[] = '-c ' . $name . '=' . $this->escapeStartupOptionValue((string) $value);
        }

        // Dedicated settings take precedence over their server_options equivalents.
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

        $lockTimeout = $this->getLockTimeout($config);

        if ($lockTimeout !== null) {
            $parts[] = "-c lock_timeout={$lockTimeout}s";
        }

        if (isset($config['synchronous_commit'])) {
            $parts[] = '-c synchronous_commit=' . $this->escapeStartupOptionValue((string) $config['synchronous_commit']);
        }

        return $parts !== [] ? $dsn . ';options=' . $this->quoteConnectionValue(implode(' ', $parts)) : $dsn;
    }

    /**
     * Escape a value for the server's options argument splitter.
     */
    protected function escapeStartupOptionValue(string $value): string
    {
        return str_replace(['\\', ' '], ['\\\\', '\ '], $value);
    }

    /**
     * Quote a value for libpq's connection-string parser.
     */
    protected function quoteConnectionValue(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Add the SSL options to the DSN.
     */
    protected function addSslOptions(string $dsn, array $config): string
    {
        foreach (['sslmode', 'sslcert', 'sslkey', 'sslrootcert'] as $option) {
            if (isset($config[$option])) {
                $dsn .= ";{$option}=" . $this->quoteConnectionValue((string) $config[$option]);
            }
        }

        return $dsn;
    }

    /**
     * Add the keepalive options to the DSN.
     */
    protected function addKeepaliveOptions(string $dsn, array $config): string
    {
        foreach (['keepalives', 'keepalives_idle', 'keepalives_interval', 'keepalives_count'] as $option) {
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
        return '"' . implode('", "', str_replace('"', '""', $searchPath)) . '"';
    }
}
