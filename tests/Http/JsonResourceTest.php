<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\MissingValue;
use Hypervel\Tests\TestCase;
use Mockery as m;

class JsonResourceTest extends TestCase
{
    public function testJsonResourceNullAttributes(): void
    {
        $model = new class extends Model {
        };

        $model->setAttribute('relation_sum_column', null);
        $model->setAttribute('relation_count', null);
        $model->setAttribute('relation_exists', null);

        $resource = new JsonResource($model);

        $this->assertNotInstanceOf(MissingValue::class, $resource->whenAggregated('relation', 'column', 'sum'));
        $this->assertNotInstanceOf(MissingValue::class, $resource->whenCounted('relation'));
        $this->assertNotInstanceOf(MissingValue::class, $resource->whenExistsLoaded('relation'));

        $this->assertNull($resource->whenAggregated('relation', 'column', 'sum'));
        $this->assertNull($resource->whenCounted('relation'));
        $this->assertNull($resource->whenExistsLoaded('relation'));
    }

    public function testJsonResourceToJsonSucceedsWithPriorErrors(): void
    {
        $model = new class extends Model {
        };

        $resource = m::mock(JsonResource::class, ['resource' => $model])
            ->makePartial()
            ->shouldReceive('jsonSerialize')->andReturn(['foo' => 'bar'])
            ->getMock();

        // Simulate a JSON error
        json_decode('{');
        $this->assertTrue(json_last_error() !== JSON_ERROR_NONE);

        $this->assertSame('{"foo":"bar"}', $resource->toJson(JSON_THROW_ON_ERROR));
    }

    public function testJsonResourceToPrettyPrint(): void
    {
        $model = new class extends Model {
        };

        $resource = m::mock(JsonResource::class, ['resource' => $model])
            ->makePartial()
            ->shouldReceive('jsonSerialize')->andReturn(['foo' => 'bar', 'bar' => 'foo', 'number' => 123])
            ->getMock();

        $results = $resource->toPrettyJson();
        $expected = $resource->toJson(JSON_PRETTY_PRINT);

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);

        $results = $resource->toPrettyJson(JSON_NUMERIC_CHECK);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
        $this->assertStringContainsString('"number": 123', $results);
    }
}
