<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Brick\Math\BigInteger;
use Hypervel\Database\Query\Grammars\MariaDbGrammar;
use Hypervel\Database\Query\Processors\MariaDbProcessor;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar as MariaDbSchemaGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Database\Schema\MariaDbSchemaState;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use Override;
use RuntimeException;

class MariaDbConnection extends MySqlConnection
{
    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'MariaDB';
    }

    /**
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'mariadb';
    }

    /**
     * Determine if the connected database is a MariaDB database.
     */
    public function isMaria(): bool
    {
        return true;
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        return Str::between(parent::getServerVersion(), '5.5.5-', '-MariaDB');
    }

    /**
     * Get the ID of the last committed transaction.
     *
     * @throws RuntimeException
     */
    public function getLastCommitId(): string
    {
        $gtid = $this->usingWritePdo(fn () => $this->selectOne('select @@log_bin as log_bin, @@gtid_binlog_pos as gtid_binlog_pos'));

        if (! $gtid->log_bin) {
            throw new RuntimeException("The MariaDB configuration 'log_bin = ON' is required to retrieve the last commit ID.");
        }

        return $gtid->gtid_binlog_pos;
    }

    /**
     * Calculate the commit ID for a set of commit IDs that includes all of them.
     *
     * @param string[] $values
     *
     * @throws RuntimeException
     */
    public function mergeCommitIds(array $values): string
    {
        if ($values === []) {
            throw new RuntimeException("The list of commit IDs can't be empty.");
        }

        return implode(',', array_keys($this->parseCommitIds($values)));
    }

    /**
     * Determine if the given commit ID has been applied.
     */
    public function hasCommitId(string $value, int $wait = 0): bool
    {
        // MASTER_GTID_WAIT only sees replicated GTIDs, so fall back to @@gtid_current_pos for the server's own writes...
        $check = $this->selectOne('select master_gtid_wait(?, 0) as master_gtid_wait, @@gtid_current_pos as gtid_current_pos', [$value]);

        if ((int) $check->master_gtid_wait === 0) {
            return true;
        }

        $expected = array_column($this->parseCommitIds([$value]), 'sequence', 'domain');

        foreach ($this->parseCommitIds([$check->gtid_current_pos]) as $gtid) {
            if (isset($expected[$gtid['domain']]) && $gtid['sequence']->isGreaterThanOrEqualTo($expected[$gtid['domain']])) {
                unset($expected[$gtid['domain']]);
            }
        }

        if ($expected === [] || $wait <= 0) {
            return $expected === [];
        }

        return (int) $this->scalar('select master_gtid_wait(?, ?)', [$value, $wait]) === 0;
    }

    /**
     * Parse a set of commit IDs (GTIDs) into the newest GTID of every MariaDB replication domain.
     *
     * @param string[] $values
     * @return array<string, array{domain: string, sequence: BigInteger}>
     *
     * @throws RuntimeException
     */
    protected function parseCommitIds(array $values): array
    {
        $gtids = $parsed = [];

        foreach (explode(',', implode(',', $values)) as $gtid) {
            $gtid = trim($gtid);
            if ($gtid === '') {
                continue;
            }

            if (! preg_match('/^(?<domain>\d+)-(?<server>\d+)-(?<sequence>\d+)$/', $gtid, $matches)) {
                throw new RuntimeException("The commit ID '{$gtid}' is not a valid MariaDB GTID.");
            }

            $domain = $matches['domain'];

            // The sequence is an unsigned 64-bit integer and may exceed PHP's integer range...
            $sequence = BigInteger::of($matches['sequence']);

            if (! isset($parsed[$domain]) || $sequence->isGreaterThan($parsed[$domain]['sequence'])) {
                $gtids[$domain] = $gtid;
                $parsed[$domain] = compact('domain', 'sequence');
            }
        }

        ksort($gtids);
        ksort($parsed);

        return array_combine($gtids, $parsed);
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): MariaDbGrammar
    {
        return new MariaDbGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): MariaDbBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MariaDbBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): MariaDbSchemaGrammar
    {
        return new MariaDbSchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection.
     */
    #[Override]
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): MariaDbSchemaState
    {
        return new MariaDbSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): MariaDbProcessor
    {
        return new MariaDbProcessor;
    }
}
