<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Ui;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Mcp\Server\Ui\Csp;
use Hypervel\Tests\TestCase;

class CspTest extends TestCase
{
    public function testSerializesAsEmptyWhenNoDomainsSet(): void
    {
        $this->assertSame([], Csp::make()->toArray());
    }

    public function testSerializesConnectDomains(): void
    {
        $csp = Csp::make()->connectDomains(['https://api.example.com']);

        $this->assertSame([
            'connectDomains' => ['https://api.example.com'],
        ], $csp->toArray());
    }

    public function testSerializesAllDomainTypes(): void
    {
        $csp = Csp::make()
            ->connectDomains(['https://api.example.com'])
            ->resourceDomains(['https://cdn.example.com'])
            ->frameDomains(['https://www.youtube.com'])
            ->baseUriDomains(['https://base.example.com']);

        $this->assertSame([
            'connectDomains' => ['https://api.example.com'],
            'resourceDomains' => ['https://cdn.example.com'],
            'frameDomains' => ['https://www.youtube.com'],
            'baseUriDomains' => ['https://base.example.com'],
        ], $csp->toArray());
    }

    public function testSupportsConstructorParameters(): void
    {
        $csp = new Csp(
            connectDomains: ['https://api.example.com'],
            resourceDomains: ['https://cdn.example.com'],
        );

        $this->assertSame([
            'connectDomains' => ['https://api.example.com'],
            'resourceDomains' => ['https://cdn.example.com'],
        ], $csp->toArray());
    }

    public function testImplementsArrayable(): void
    {
        $this->assertInstanceOf(Arrayable::class, new Csp);
    }
}
