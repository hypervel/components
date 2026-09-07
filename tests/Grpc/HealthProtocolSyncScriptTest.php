<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Grpc\Resources\Proto\addHypervelPhpNamespaces;
use function Hypervel\Grpc\Resources\Proto\replaceGrpcHealthProtocolRevision;
use function Hypervel\Grpc\Resources\Proto\synchronizeGrpcHealthProtocol;

require_once dirname(__DIR__, 2) . '/src/grpc/resources/proto/sync-health-protocol.php';

class HealthProtocolSyncScriptTest extends TestCase
{
    protected string $temporaryDirectory;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->temporaryDirectory = ParallelTesting::tempDir('GrpcHealthProtocolSyncScriptTest');
        $this->filesystem->deleteDirectory($this->temporaryDirectory);
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testItAddsTheHypervelNamespacesToTheCanonicalProtocol(): void
    {
        $committed = file_get_contents(
            dirname(__DIR__, 2) . '/src/grpc/resources/proto/grpc/health/v1/health.proto',
        );
        $upstream = str_replace([
            'option php_metadata_namespace = "Hypervel\\\Grpc\\\Health\\\V1\\\Metadata";' . "\n",
            'option php_namespace = "Hypervel\\\Grpc\\\Health\\\V1";' . "\n",
        ], '', $committed);

        $this->assertSame($committed, addHypervelPhpNamespaces($upstream));
    }

    public function testItRejectsAnUpstreamPhpNamespace(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The upstream gRPC health protocol already declares a PHP namespace.');

        addHypervelPhpNamespaces(<<<'PROTO'
syntax = "proto3";

package grpc.health.v1;

option php_namespace = "Grpc\\Health\\V1";
PROTO);
    }

    public function testItLeavesTheRevisionUntouchedWhenTheProtocolHasNotChanged(): void
    {
        [$upstream, $protocolPath, $readmePath] = $this->writeProtocolFixture();
        $readme = file_get_contents($readmePath);

        $generated = false;

        $this->assertFalse(synchronizeGrpcHealthProtocol(
            $upstream,
            str_repeat('b', 40),
            $protocolPath,
            $readmePath,
            static function () use (&$generated): void {
                $generated = true;
            },
        ));
        $this->assertFalse($generated);
        $this->assertSame($readme, file_get_contents($readmePath));
    }

    public function testItPublishesAChangedProtocolAndItsRevision(): void
    {
        [$upstream, $protocolPath, $readmePath] = $this->writeProtocolFixture();
        $revision = str_repeat('b', 40);
        $updatedUpstream = str_replace(
            'message HealthCheckRequest {}',
            'message HealthCheckRequest { string service = 1; }',
            $upstream,
        );

        $generated = false;

        $this->assertTrue(synchronizeGrpcHealthProtocol(
            $updatedUpstream,
            $revision,
            $protocolPath,
            $readmePath,
            static function () use (&$generated): void {
                $generated = true;
            },
        ));
        $this->assertTrue($generated);
        $this->assertSame(addHypervelPhpNamespaces($updatedUpstream), file_get_contents($protocolPath));
        $this->assertSame(
            "The health schema is copied from `grpc/grpc-proto` revision\n`{$revision}`.\n",
            file_get_contents($readmePath),
        );
        $this->assertSame(0644, fileperms($protocolPath) & 0777);
        $this->assertSame(0644, fileperms($readmePath) & 0777);
        $this->assertSame([], glob($this->temporaryDirectory . '/.grpc-health-*'));
    }

    public function testItRestoresTheProtocolAndRevisionWhenGenerationFails(): void
    {
        [$upstream, $protocolPath, $readmePath] = $this->writeProtocolFixture();
        $protocol = file_get_contents($protocolPath);
        $readme = file_get_contents($readmePath);
        $updatedUpstream = str_replace(
            'message HealthCheckRequest {}',
            'message HealthCheckRequest { string service = 1; }',
            $upstream,
        );

        try {
            synchronizeGrpcHealthProtocol(
                $updatedUpstream,
                str_repeat('b', 40),
                $protocolPath,
                $readmePath,
                static fn (): never => throw new RuntimeException('Generation failed.'),
            );
            $this->fail('Expected gRPC health protocol generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Generation failed.', $exception->getMessage());
        }

        $this->assertSame($protocol, file_get_contents($protocolPath));
        $this->assertSame($readme, file_get_contents($readmePath));
        $this->assertSame([], glob($this->temporaryDirectory . '/.grpc-health-*'));
    }

    public function testTheCommittedReadmeContainsTheReplaceablePinnedRevision(): void
    {
        $revision = str_repeat('b', 40);
        $readme = file_get_contents(
            dirname(__DIR__, 2) . '/src/grpc/resources/proto/README.md',
        );
        preg_match('/\b[0-9a-f]{40}\b/', $readme, $matches);
        $currentRevision = $matches[0] ?? null;

        $this->assertNotNull($currentRevision);

        $updatedReadme = replaceGrpcHealthProtocolRevision($readme, $revision);

        $this->assertStringContainsString($revision, $updatedReadme);
        $this->assertStringNotContainsString($currentRevision, $updatedReadme);
    }

    /**
     * Write a current protocol and README fixture.
     *
     * @return array{string, string, string}
     */
    protected function writeProtocolFixture(): array
    {
        $upstream = <<<'PROTO'
syntax = "proto3";

package grpc.health.v1;

option java_package = "io.grpc.health.v1";
option objc_class_prefix = "GrpcHealthV1";

message HealthCheckRequest {}
PROTO;
        $protocolPath = $this->temporaryDirectory . '/health.proto';
        $readmePath = $this->temporaryDirectory . '/README.md';

        file_put_contents($protocolPath, addHypervelPhpNamespaces($upstream));
        file_put_contents(
            $readmePath,
            "The health schema is copied from `grpc/grpc-proto` revision\n`" . str_repeat('a', 40) . "`.\n",
        );
        chmod($protocolPath, 0644);
        chmod($readmePath, 0644);

        return [$upstream, $protocolPath, $readmePath];
    }
}
