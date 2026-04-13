<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class FakeResource extends JsonResource
{
    /**
     * The data that will be used.
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * The "data" wrapper that should be applied.
     */
    public static ?string $wrap = null;

    /**
     * @param array<string, mixed> $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct(null);
        $this->data = $resource;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->data;
    }
}
