<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\MySql;

use Hypervel\SchemaParser\MySql\SqlParser;
use Hypervel\Tests\TestCase;

class TablePropsTest extends TestCase
{
    # table_options:
    #     table_option [[,] table_option] ...
    #
    # table_option:
    #     AUTO_INCREMENT [=] value
    #   | AVG_ROW_LENGTH [=] value
    #   | [DEFAULT] CHARACTER SET [=] charset_name
    #   | CHECKSUM [=] {0 | 1}
    #   | [DEFAULT] COLLATE [=] collation_name
    #   | COMMENT [=] 'string'
    #   | COMPRESSION [=] {'ZLIB'|'LZ4'|'NONE'}
    #   | CONNECTION [=] 'connect_string'
    #   | {DATA|INDEX} DIRECTORY [=] 'absolute path to directory'
    #   | DELAY_KEY_WRITE [=] {0 | 1}
    #   | ENCRYPTION [=] {'Y' | 'N'}
    #   | ENGINE [=] engine_name
    #   | INSERT_METHOD [=] { NO | FIRST | LAST }
    #   | KEY_BLOCK_SIZE [=] value
    #   | MAX_ROWS [=] value
    #   | MIN_ROWS [=] value
    #   | PACK_KEYS [=] {0 | 1 | DEFAULT}
    #   | PASSWORD [=] 'string'
    #   | ROW_FORMAT [=] {DEFAULT|DYNAMIC|FIXED|COMPRESSED|REDUNDANT|COMPACT}
    #   | STATS_AUTO_RECALC [=] {DEFAULT|0|1}
    #   | STATS_PERSISTENT [=] {DEFAULT|0|1}
    #   | STATS_SAMPLE_PAGES [=] value
    #   | TABLESPACE tablespace_name [STORAGE {DISK|MEMORY|DEFAULT}]
    #   | UNION [=] (tbl_name[,tbl_name]...)

    public function testEqualsIsOptional(): void
    {
        # the equals is optional

        $this->table_props_test(['ENGINE', '=', 'INNODB'], ['ENGINE' => 'INNODB']);
        $this->table_props_test(['ENGINE', 'INNODB'], ['ENGINE' => 'INNODB']);
    }

    public function testDefaultCharset(): void
    {
        # lots of ways to say this

        $this->table_props_test(['DEFAULT CHARACTER SET', 'foo'], ['CHARSET' => 'foo']);
        $this->table_props_test(['CHARACTER SET', 'foo'], ['CHARSET' => 'foo']);
        $this->table_props_test(['DEFAULT CHARSET', 'foo'], ['CHARSET' => 'foo']);
        $this->table_props_test(['CHARSET', 'foo'], ['CHARSET' => 'foo']);
    }

    public function testDefaultCollation(): void
    {
        $this->table_props_test(['DEFAULT COLLATE', 'bar'], ['COLLATE' => 'bar']);
        $this->table_props_test(['COLLATE', 'bar'], ['COLLATE' => 'bar']);
    }

    public function testTwoWordProps(): void
    {
        # more two-word props

        $this->table_props_test(['DATA DIRECTORY', '=', 'baz'], ['DATA DIRECTORY' => 'baz']);
        $this->table_props_test(['INDEX DIRECTORY', '=', 'baz'], ['INDEX DIRECTORY' => 'baz']);
    }

    public function testCaseInsensitiveOptionsWithOptionalCommas(): void
    {
        $this->table_props_test(
            ['tablespace', 'app', 'storage', 'DISK', 'engine', '=', 'NDB', ',', 'default charset', 'utf8mb4', '`engine_option`', '=', "'value'", 'comment', '=', "'a  b'"],
            ['TABLESPACE' => 'app', 'STORAGE' => 'DISK', 'ENGINE' => 'NDB', 'CHARSET' => 'utf8mb4', 'ENGINE_OPTION' => "'value'", 'COMMENT' => "'a  b'"],
        );
    }

    /**
     * Assert the parsed table options.
     *
     * @param list<string> $tokens
     * @param array<string, string> $expected
     */
    private function table_props_test(array $tokens, array $expected): void
    {
        $obj = new SqlParser;
        $i = 0;
        $props = $obj->parse_table_props($tokens, $i);

        $this->assertSame($expected, $props);
    }
}
