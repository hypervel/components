<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Support;

use Hypervel\Cache\Redis\Support\TagKeyBuilder;
use Hypervel\Cache\TagMode;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TagKeyBuilderTest extends TestCase
{
    #[DataProvider('tagSegmentProvider')]
    public function testTagSegmentReturnsExpectedFormat(TagMode $mode, string $expected): void
    {
        $builder = new TagKeyBuilder($mode, 'app:');

        $this->assertSame($expected, $builder->tagSegment());
    }

    #[DataProvider('tagSegmentProvider')]
    public function testTagSegmentForReturnsExpectedFormat(TagMode $mode, string $expected): void
    {
        $this->assertSame($expected, TagKeyBuilder::tagSegmentFor($mode));
    }

    public static function tagSegmentProvider(): array
    {
        return [
            'any mode' => [TagMode::Any, '_any:tag:'],
            'all mode' => [TagMode::All, '_all:tag:'],
        ];
    }

    #[DataProvider('tagIdProvider')]
    public function testTagIdCombinesSegmentWithEntriesSuffix(TagMode $mode, string $expected): void
    {
        $builder = new TagKeyBuilder($mode, 'app:');

        $this->assertSame($expected, $builder->tagId('users'));
    }

    public static function tagIdProvider(): array
    {
        return [
            'any mode' => [TagMode::Any, '_any:tag:users:entries'],
            'all mode' => [TagMode::All, '_all:tag:users:entries'],
        ];
    }

    #[DataProvider('tagKeyProvider')]
    public function testTagKeyPrependsPrefix(TagMode $mode, string $expected): void
    {
        $builder = new TagKeyBuilder($mode, 'app:');

        $this->assertSame($expected, $builder->tagKey('users'));
    }

    public static function tagKeyProvider(): array
    {
        return [
            'any mode' => [TagMode::Any, 'app:_any:tag:users:entries'],
            'all mode' => [TagMode::All, 'app:_all:tag:users:entries'],
        ];
    }

    #[DataProvider('reverseIndexKeyProvider')]
    public function testReverseIndexKeyCombinesPrefixCacheKeySuffix(TagMode $mode, string $expected): void
    {
        $builder = new TagKeyBuilder($mode, 'app:');

        $this->assertSame($expected, $builder->reverseIndexKey('user:1'));
    }

    public static function reverseIndexKeyProvider(): array
    {
        return [
            'any mode' => [TagMode::Any, 'app:user:1:_any:tags'],
            'all mode' => [TagMode::All, 'app:user:1:_all:tags'],
        ];
    }

    #[DataProvider('registryKeyProvider')]
    public function testRegistryKeyCombinesPrefixAndTagSegmentWithRegistry(TagMode $mode, string $expected): void
    {
        $builder = new TagKeyBuilder($mode, 'app:');

        $this->assertSame($expected, $builder->registryKey());
    }

    public static function registryKeyProvider(): array
    {
        return [
            'any mode' => [TagMode::Any, 'app:_any:tag:registry'],
            'all mode' => [TagMode::All, 'app:_all:tag:registry'],
        ];
    }
}
