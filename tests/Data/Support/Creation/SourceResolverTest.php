<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\NormalizedModel;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\Creation\SourceResolver;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;

class SourceResolverTest extends TestCase
{
    /**
     * Test already-normalized and null sources take their fixed forms.
     */
    public function testResolvesNullAndNormalizedSourcesWithoutRunningCustomNormalizers(): void
    {
        $normalized = new class implements Normalized {
            public function getProperty(string $name, DataProperty $dataProperty): mixed
            {
                return UnknownProperty::create();
            }
        };
        $normalizer = new class implements Normalizer {
            public function normalize(mixed $value): array|Normalized|null
            {
                return ['intercepted' => true];
            }
        };

        $this->assertSame([], SourceResolver::resolve(self::class, null, [$normalizer]));
        $this->assertSame($normalized, SourceResolver::resolve(self::class, $normalized, [$normalizer]));
    }

    /**
     * Test class and configured normalizers run before fixed source handling.
     */
    public function testFirstCustomNormalizerWinsBeforeFixedArrayHandling(): void
    {
        $skipped = new class implements Normalizer {
            public function normalize(mixed $value): array|Normalized|null
            {
                return null;
            }
        };
        $accepted = new class implements Normalizer {
            public function normalize(mixed $value): array|Normalized|null
            {
                return ['custom' => $value['original']];
            }
        };

        $this->assertSame(
            ['custom' => 'value'],
            SourceResolver::resolve(self::class, ['original' => 'value'], [$skipped, $accepted]),
        );
    }

    /**
     * Test all fixed source adapters preserve their intended representation.
     */
    public function testResolvesFixedSourceTypes(): void
    {
        $request = Request::create('/', 'POST', ['request' => true]);
        $arrayable = new class implements Arrayable {
            public string $source = 'object';

            public function toArray(): array
            {
                return ['source' => 'arrayable'];
            }
        };
        $object = new class {
            public string $initialized = 'value';

            public string $uninitialized;

            private string $hidden = 'hidden';
        };
        $model = new class extends Model {
        };

        $this->assertSame(['array' => true], SourceResolver::resolve(self::class, ['array' => true], []));
        $this->assertSame(['request' => true], SourceResolver::resolve(self::class, $request, []));
        $this->assertSame(['source' => 'arrayable'], SourceResolver::resolve(self::class, $arrayable, []));
        $this->assertSame(['initialized' => 'value'], SourceResolver::resolve(self::class, $object, []));
        $this->assertSame(['json' => true], SourceResolver::resolve(self::class, '{"json":true}', []));
        $this->assertInstanceOf(NormalizedModel::class, SourceResolver::resolve(self::class, $model, []));
    }

    /**
     * Test unsupported and invalid JSON values fail with a creation exception.
     */
    public function testThrowsWhenNoFixedOrCustomNormalizerAcceptsTheValue(): void
    {
        foreach ([42, 'not-json', 'null'] as $value) {
            try {
                SourceResolver::resolve(self::class, $value, []);
                $this->fail('Expected the source to be rejected.');
            } catch (CannotCreateData $exception) {
                $this->assertStringContainsString('no normalizer accepted', $exception->getMessage());
            }
        }
    }
}
