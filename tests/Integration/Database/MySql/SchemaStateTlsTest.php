<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use Hypervel\Database\MySqlConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Pdo\Mysql;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

#[RequiresDatabase('mysql', '>=8.0.16')]
class SchemaStateTlsTest extends MySqlTestCase
{
    protected Filesystem $filesystem;

    protected string $directory;

    protected function setUp(): void
    {
        if (! getenv('DB_TEST_SCHEMA_TLS_DIRECTORY')) {
            $this->markTestSkipped('Configure DB_TEST_SCHEMA_TLS_DIRECTORY to run the serial schema TLS test.');
        }

        parent::setUp();

        $this->filesystem = $this->app->make(Filesystem::class);
        $this->directory = rtrim(getenv('DB_TEST_SCHEMA_TLS_DIRECTORY'), '/') . '/schema-state';
        $this->filesystem->deleteDirectory($this->directory);
        $this->filesystem->ensureDirectoryExists($this->directory);
        chmod($this->directory, 0755);
        $this->beforeApplicationDestroyed(fn () => $this->filesystem->deleteDirectory($this->directory));
    }

    public function testDumpAndLoadRequireATrustedServerCertificate(): void
    {
        $connection = DB::connection();
        $this->createCertificates($connection->getConfig('host'));

        $connection->withPinnedSession(function () use ($connection): void {
            $previous = $connection->selectOne('SELECT @@GLOBAL.ssl_ca AS ca, @@GLOBAL.ssl_cert AS cert, @@GLOBAL.ssl_key AS `key`');

            try {
                foreach (['ca' => 'ca.pem', 'cert' => 'server.pem', 'key' => 'server.key'] as $option => $file) {
                    $connection->statement("SET GLOBAL ssl_{$option} = ?", [$this->directory . '/' . $file]);
                }
                $connection->statement('ALTER INSTANCE RELOAD TLS');
                $connection->statement('CREATE TABLE schema_tls_records (id int PRIMARY KEY)');
                $path = $this->directory . '/schema.sql';

                foreach (['trusted' => 'ca.pem', 'untrusted' => 'untrusted-ca.pem'] as $trust => $file) {
                    $config = $connection->getConfig();
                    $config['options'] = array_replace($config['options'] ?? [], [
                        Mysql::ATTR_SSL_CA => $this->directory . '/' . $file,
                        Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
                    ]);
                    // Reuse the pinned administrative PDO so a bad CA reaches the native client,
                    // rather than failing PDO's server-version lookup before the dump starts.
                    $subject = new MySqlConnection($connection->getPdo(), $connection->getDatabaseName(), $connection->getTablePrefix(), $config);
                    $state = $subject->getSchemaState(processFactory: static fn (string $command): Process => Process::fromShellCommandline($command)->setIdleTimeout(20))
                        ->withMigrationTable(null);

                    if ($trust === 'trusted') {
                        $state->dump($subject, $path);
                        $connection->statement('DROP TABLE schema_tls_records');
                        $state->load($path);

                        $this->assertSame(['id'], $connection->getSchemaBuilder()->getColumnListing('schema_tls_records'));
                    } else {
                        $this->assertCertificateRejected(fn () => $state->dump($subject, $this->directory . '/rejected.sql'));
                        $this->assertCertificateRejected(fn () => $state->load($path));
                    }
                }
            } finally {
                try {
                    $connection->statement('DROP TABLE IF EXISTS schema_tls_records');
                } finally {
                    foreach (['ca', 'cert', 'key'] as $option) {
                        $connection->statement("SET GLOBAL ssl_{$option} = ?", [$previous->{$option}]);
                    }
                    $connection->statement('ALTER INSTANCE RELOAD TLS');
                }
            }
        });
    }

    /**
     * Create short-lived certificates readable at the same path by the client and server.
     */
    protected function createCertificates(string $host): void
    {
        $subjectAlternativeName = (filter_var($host, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:') . $host;
        $this->filesystem->put($this->directory . '/openssl.cnf', <<<INI
            [req]
            distinguished_name = distinguished_name
            [distinguished_name]
            [ca]
            basicConstraints = critical,CA:true
            keyUsage = critical,keyCertSign,cRLSign
            [server]
            basicConstraints = critical,CA:false
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = {$subjectAlternativeName}
            INI);
        [$authority, $authorityKey] = $this->createCertificate('Hypervel schema test CA', 'ca');
        [$untrusted] = $this->createCertificate('Untrusted schema test CA', 'ca');
        [$server, $serverKey] = $this->createCertificate($host, 'server', $authority, $authorityKey);

        openssl_x509_export_to_file($authority, $this->directory . '/ca.pem');
        openssl_x509_export_to_file($untrusted, $this->directory . '/untrusted-ca.pem');
        openssl_x509_export_to_file($server, $this->directory . '/server.pem');
        openssl_pkey_export_to_file($serverKey, $this->directory . '/server.key');

        foreach (['ca.pem', 'untrusted-ca.pem', 'server.pem', 'server.key'] as $file) {
            chmod($this->directory . '/' . $file, 0644);
        }
    }

    /**
     * Issue a certificate with the requested test extensions.
     *
     * @return array{OpenSSLCertificate, OpenSSLAsymmetricKey}
     */
    protected function createCertificate(string $name, string $extensions, ?OpenSSLCertificate $issuer = null, ?OpenSSLAsymmetricKey $issuerKey = null): array
    {
        $options = ['config' => $this->directory . '/openssl.cnf', 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => $name], $key, $options);
        $certificate = openssl_csr_sign($request, $issuer, $issuerKey ?? $key, 1, $options + ['x509_extensions' => $extensions]);

        return [$certificate, $key];
    }

    /**
     * Require a native certificate-verification error rather than an unrelated command failure.
     */
    protected function assertCertificateRejected(callable $operation): void
    {
        try {
            $operation();
        } catch (ProcessFailedException $exception) {
            $this->assertMatchesRegularExpression(
                '/(?:certificate verify failed|self.signed certificate|unable to get local issuer certificate|Validation of SSL server certificate failed)/i',
                $exception->getProcess()->getErrorOutput(),
            );

            return;
        }

        $this->fail('The native client accepted a server certificate signed by an untrusted CA.');
    }
}
