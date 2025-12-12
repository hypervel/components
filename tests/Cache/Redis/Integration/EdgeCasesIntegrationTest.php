<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for edge cases in both tag modes.
 *
 * Tests handling of:
 * - Special characters in keys and tags
 * - Unicode characters
 * - Very long keys and tags
 * - Numeric tags
 * - Zero as value
 * - Keys resembling Redis commands
 * - Binary data
 * - Empty arrays
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class EdgeCasesIntegrationTest extends CacheRedisIntegrationTestCase
{
    // =========================================================================
    // SPECIAL CHARACTERS IN CACHE KEYS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesSpecialCharactersInCacheKeys(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertSpecialCharacterKeysWork();
    }

    public function testAnyModeHandlesSpecialCharactersInCacheKeys(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertSpecialCharacterKeysWork();
    }

    // =========================================================================
    // SPECIAL CHARACTERS IN TAG NAMES - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesSpecialCharactersInTagNames(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertSpecialCharacterTagsWork();
    }

    public function testAnyModeHandlesSpecialCharactersInTagNames(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertSpecialCharacterTagsWork();
    }

    // =========================================================================
    // VERY LONG KEYS AND TAGS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesVeryLongCacheKeys(): void
    {
        $this->setTagMode(TagMode::All);
        $longKey = str_repeat('a', 1000);

        Cache::tags(['long'])->put($longKey, 'value', 60);
        $this->assertSame('value', Cache::tags(['long'])->get($longKey));

        Cache::tags(['long'])->flush();
        $this->assertNull(Cache::tags(['long'])->get($longKey));
    }

    public function testAnyModeHandlesVeryLongCacheKeys(): void
    {
        $this->setTagMode(TagMode::Any);
        $longKey = str_repeat('a', 1000);

        Cache::tags(['long'])->put($longKey, 'value', 60);
        $this->assertSame('value', Cache::get($longKey));

        Cache::tags(['long'])->flush();
        $this->assertNull(Cache::get($longKey));
    }

    public function testAllModeHandlesVeryLongTagNames(): void
    {
        $this->setTagMode(TagMode::All);
        $longTag = str_repeat('tag', 100);

        Cache::tags([$longTag])->put('item', 'value', 60);
        $this->assertSame('value', Cache::tags([$longTag])->get('item'));

        Cache::tags([$longTag])->flush();
        $this->assertNull(Cache::tags([$longTag])->get('item'));
    }

    public function testAnyModeHandlesVeryLongTagNames(): void
    {
        $this->setTagMode(TagMode::Any);
        $longTag = str_repeat('tag', 100);

        Cache::tags([$longTag])->put('item', 'value', 60);
        $this->assertSame('value', Cache::get('item'));

        Cache::tags([$longTag])->flush();
        $this->assertNull(Cache::get('item'));
    }

    // =========================================================================
    // UNICODE CHARACTERS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesUnicodeCharactersInKeys(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertUnicodeKeysWork();
    }

    public function testAnyModeHandlesUnicodeCharactersInKeys(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertUnicodeKeysWork();
    }

    // =========================================================================
    // NUMERIC TAGS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesNumericTagNames(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['123', '456'])->put('numeric', 'value', 60);
        $this->assertSame('value', Cache::tags(['123', '456'])->get('numeric'));

        Cache::tags(['123'])->flush();
        $this->assertNull(Cache::tags(['123', '456'])->get('numeric'));
    }

    public function testAnyModeHandlesNumericTagNames(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['123', '456'])->put('numeric', 'value', 60);
        $this->assertSame('value', Cache::get('numeric'));

        Cache::tags(['123'])->flush();
        $this->assertNull(Cache::get('numeric'));
    }

    // =========================================================================
    // ZERO AS VALUE - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesZeroAsValue(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['zeros'])->put('int-zero', 0, 60);
        Cache::tags(['zeros'])->put('float-zero', 0.0, 60);
        Cache::tags(['zeros'])->put('string-zero', '0', 60);

        $this->assertEquals(0, Cache::tags(['zeros'])->get('int-zero'));
        $this->assertEquals(0.0, Cache::tags(['zeros'])->get('float-zero'));
        $this->assertSame('0', Cache::tags(['zeros'])->get('string-zero'));
    }

    public function testAnyModeHandlesZeroAsValue(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['zeros'])->put('int-zero', 0, 60);
        Cache::tags(['zeros'])->put('float-zero', 0.0, 60);
        Cache::tags(['zeros'])->put('string-zero', '0', 60);

        $this->assertEquals(0, Cache::get('int-zero'));
        $this->assertEquals(0.0, Cache::get('float-zero'));
        $this->assertSame('0', Cache::get('string-zero'));
    }

    // =========================================================================
    // KEYS RESEMBLING REDIS COMMANDS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesKeysLikeRedisCommands(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertRedisCommandLikeKeysWork();
    }

    public function testAnyModeHandlesKeysLikeRedisCommands(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertRedisCommandLikeKeysWork();
    }

    // =========================================================================
    // BINARY DATA - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesBinaryData(): void
    {
        $this->setTagMode(TagMode::All);

        $binaryData = random_bytes(256);

        Cache::tags(['binary'])->put('binary-data', $binaryData, 60);
        $retrieved = Cache::tags(['binary'])->get('binary-data');

        $this->assertSame($binaryData, $retrieved);
    }

    public function testAnyModeHandlesBinaryData(): void
    {
        $this->setTagMode(TagMode::Any);

        $binaryData = random_bytes(256);

        Cache::tags(['binary'])->put('binary-data', $binaryData, 60);
        $retrieved = Cache::get('binary-data');

        $this->assertSame($binaryData, $retrieved);
    }

    // =========================================================================
    // MAXIMUM NUMBER OF TAGS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesManyTags(): void
    {
        $this->setTagMode(TagMode::All);

        $tags = [];
        for ($i = 0; $i < 50; ++$i) {
            $tags[] = "tag_{$i}";
        }

        Cache::tags($tags)->put('many-tags', 'value', 60);
        $this->assertSame('value', Cache::tags($tags)->get('many-tags'));

        // Flush by any one of the tags
        Cache::tags(['tag_25'])->flush();
        $this->assertNull(Cache::tags($tags)->get('many-tags'));
    }

    public function testAnyModeHandlesManyTags(): void
    {
        $this->setTagMode(TagMode::Any);

        $tags = [];
        for ($i = 0; $i < 50; ++$i) {
            $tags[] = "tag_{$i}";
        }

        Cache::tags($tags)->put('many-tags', 'value', 60);
        $this->assertSame('value', Cache::get('many-tags'));

        // Flush by any one of the tags
        Cache::tags(['tag_25'])->flush();
        $this->assertNull(Cache::get('many-tags'));
    }

    // =========================================================================
    // WHITESPACE IN KEYS - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesWhitespaceInKeys(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertWhitespaceKeysWork();
    }

    public function testAnyModeHandlesWhitespaceInKeys(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertWhitespaceKeysWork();
    }

    // =========================================================================
    // NON-EXISTENT KEYS - BOTH MODES
    // =========================================================================

    public function testAllModeReturnsNullForNonExistentKeys(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertNull(Cache::get('non.existent'));
        $this->assertNull(Cache::tags(['sometag'])->get('non.existent'));
    }

    public function testAnyModeReturnsNullForNonExistentKeys(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertNull(Cache::get('non.existent'));
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function assertSpecialCharacterKeysWork(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        $get = fn (string $key) => $isAnyMode ? Cache::get($key) : Cache::tags(['special'])->get($key);

        $keys = [
            'key:with:colons' => 'value1',
            'key-with-dashes' => 'value2',
            'key_with_underscores' => 'value3',
            'key.with.dots' => 'value4',
            'key@with#special$chars' => 'value5',
            'key with spaces' => 'value6',
            'key[with]brackets' => 'value7',
            'key{with}braces' => 'value8',
        ];

        foreach ($keys as $key => $value) {
            Cache::tags(['special'])->put($key, $value, 60);
        }

        foreach ($keys as $key => $value) {
            $this->assertSame($value, $get($key));
        }

        Cache::tags(['special'])->flush();

        foreach ($keys as $key => $value) {
            $this->assertNull($get($key));
        }
    }

    private function assertSpecialCharacterTagsWork(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();

        $tags = [
            'tag:with:colons',
            'tag-with-dashes',
            'tag_with_underscores',
            'tag.with.dots',
            'tag@special',
            'user:123',
            'namespace::class',
        ];

        foreach ($tags as $tag) {
            Cache::tags([$tag])->put('item', 'value', 60);
            $value = $isAnyMode ? Cache::get('item') : Cache::tags([$tag])->get('item');
            $this->assertSame('value', $value, "Failed to retrieve item for tag: {$tag}");

            Cache::tags([$tag])->flush();
            $value = $isAnyMode ? Cache::get('item') : Cache::tags([$tag])->get('item');
            $this->assertNull($value, "Failed to flush item for tag: {$tag}");
        }
    }

    private function assertUnicodeKeysWork(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        $get = fn (string $key) => $isAnyMode ? Cache::get($key) : Cache::tags(['unicode'])->get($key);

        $unicodeKeys = [
            'key_中文_chinese' => 'value1',
            'key_العربية_arabic' => 'value2',
            'key_한글_korean' => 'value3',
            'key_русский_russian' => 'value4',
            'key_日本語_japanese' => 'value5',
        ];

        foreach ($unicodeKeys as $key => $value) {
            Cache::tags(['unicode'])->put($key, $value, 60);
        }

        foreach ($unicodeKeys as $key => $value) {
            $this->assertSame($value, $get($key), "Failed to retrieve unicode key: {$key}");
        }
    }

    private function assertRedisCommandLikeKeysWork(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        $get = fn (string $key) => $isAnyMode ? Cache::get($key) : Cache::tags(['commands'])->get($key);

        $suspiciousKeys = [
            'SET' => 'value1',
            'GET' => 'value2',
            'DEL' => 'value3',
            'FLUSHDB' => 'value4',
            'EVAL' => 'value5',
        ];

        foreach ($suspiciousKeys as $key => $value) {
            Cache::tags(['commands'])->put($key, $value, 60);
        }

        foreach ($suspiciousKeys as $key => $value) {
            $this->assertSame($value, $get($key));
        }
    }

    private function assertWhitespaceKeysWork(): void
    {
        $isAnyMode = $this->getTagMode()->isAnyMode();
        $get = fn (string $key) => $isAnyMode ? Cache::get($key) : Cache::tags(['whitespace'])->get($key);

        $whitespaceKeys = [
            "key\twith\ttabs" => 'value1',
            '  key with leading spaces' => 'value2',
            'key with trailing spaces  ' => 'value3',
        ];

        foreach ($whitespaceKeys as $key => $value) {
            Cache::tags(['whitespace'])->put($key, $value, 60);
        }

        foreach ($whitespaceKeys as $key => $value) {
            $this->assertSame($value, $get($key));
        }
    }
}
