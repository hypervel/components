<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\ProvidesResourceWrapper;
use Hypervel\Testbench\TestCase;

class ResourceResponseTest extends TestCase
{
    public function testLegacyStaticWrapperRemainsUnchanged(): void
    {
        $response = (new LegacyWrappedResource(['name' => 'Taylor']))
            ->toResponse(Request::create('/'));

        $this->assertSame([
            'legacy' => ['name' => 'Taylor'],
        ], $response->getData(true));
    }

    public function testInstanceWrapperOverridesStaticWrapper(): void
    {
        $response = (new InstanceWrappedResource(['name' => 'Taylor'], 'payload'))
            ->toResponse(Request::create('/'));

        $this->assertSame([
            'payload' => ['name' => 'Taylor'],
        ], $response->getData(true));
    }

    public function testNullInstanceWrapperIsAuthoritativeWhenForcedWrappingIsEnabled(): void
    {
        $response = (new ForceWrappedResource(['name' => 'Taylor'], null))
            ->toResponse(Request::create('/'));

        $this->assertSame(['name' => 'Taylor'], $response->getData(true));
    }
}

class LegacyWrappedResource extends JsonResource
{
    public static ?string $wrap = 'legacy';
}

class InstanceWrappedResource extends JsonResource implements ProvidesResourceWrapper
{
    public static ?string $wrap = 'legacy';

    public function __construct(mixed $resource, protected readonly ?string $instanceWrapper)
    {
        parent::__construct($resource);
    }

    public function resourceWrapper(): ?string
    {
        return $this->instanceWrapper;
    }
}

class ForceWrappedResource extends InstanceWrappedResource
{
    public static bool $forceWrapping = true;
}
