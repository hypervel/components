<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Enums;

use Hypervel\Mcp\Enums\ProtocolHandshake;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Tests\TestCase;

class ProtocolVersionTest extends TestCase
{
    public function testDefinesTheHandshakeForEachProtocolVersionExplicitly(): void
    {
        $this->assertSame(ProtocolHandshake::Discovery, ProtocolVersion::V2026_07_28->handshake());
        $this->assertSame(ProtocolHandshake::Initialize, ProtocolVersion::V2025_11_25->handshake());
        $this->assertSame(ProtocolHandshake::Initialize, ProtocolVersion::V2025_06_18->handshake());
    }

    public function testListsVersionsSupportedByEachProtocolFlow(): void
    {
        $this->assertSame(['2026-07-28'], ProtocolVersion::serverSupported());
        $this->assertSame(['2026-07-28', '2025-11-25', '2025-06-18'], ProtocolVersion::clientSupported());
        $this->assertSame(['2025-11-25', '2025-06-18'], ProtocolVersion::initializeSupported());
    }

    public function testPicksTheVersionThisClientPrefersRegardlessOfTheOrderTheServerLists(): void
    {
        $this->assertSame(ProtocolVersion::V2025_11_25, ProtocolVersion::preferredFrom('2025-06-18', '2025-11-25'));
        $this->assertSame(ProtocolVersion::LATEST, ProtocolVersion::preferredFrom('2025-06-18', '2026-07-28'));
    }

    public function testHasNoMutualVersionWhenTheServerSharesNone(): void
    {
        $this->assertNull(ProtocolVersion::preferredFrom('2024-11-05', '2027-01-01'));
    }

    public function testDeclaresTheCasesNewestFirstWhichIsThePreferenceOrder(): void
    {
        $values = array_column(ProtocolVersion::cases(), 'value');
        $sorted = $values;

        rsort($sorted);

        $this->assertSame($sorted, $values);
        $this->assertSame(ProtocolVersion::LATEST, ProtocolVersion::cases()[0]);
    }
}
