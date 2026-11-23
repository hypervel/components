<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Scout\SearchableSchema;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SearchableSchemaTest extends TestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        SearchableSchema::register();
    }

    public function testVectorExpressionWeightsColumnsAndCoalescesNulls(): void
    {
        $this->assertSame(
            "setweight(to_tsvector('english', coalesce(\"title\"::text, '')), 'A') || setweight(to_tsvector('english', coalesce(\"body\"::text, '')), 'D')",
            SearchableSchema::vectorExpression(['title' => 'a', 'body'], 'english')
        );
    }

    public function testVectorExpressionAcceptsSchemaQualifiedLanguages(): void
    {
        $this->assertStringContainsString("to_tsvector('public.english_unaccent'", SearchableSchema::vectorExpression(['title'], 'public.english_unaccent'));
    }

    public function testTheTrigramExtensionIsCreatedBeforeItsIndexWhenTheTableHasAForeignKey(): void
    {
        $blueprint = new Blueprint($this->getConnection(), 'posts', function (Blueprint $table): void {
            $table->create();
            $table->foreignId('user_id')->constrained();
            $table->text('title');
            $table->searchable(['title'], trigram: ['title']);
        });

        $statements = $blueprint->toSql();
        $offsetOf = fn (string $needle): int => array_find_key($statements, fn (string $statement): bool => str_contains($statement, $needle));

        $this->assertLessThan($offsetOf('gin_trgm_ops'), $offsetOf('create extension if not exists pg_trgm'));
        $this->assertLessThan($offsetOf('foreign key'), $offsetOf('gin_trgm_ops'));
    }

    public function testDropSearchableDropsTheTrigramIndexItCreatedOnASchemaQualifiedPrefixedTable(): void
    {
        $create = new Blueprint($this->getConnection('pre_'), 'tenant.posts', function (Blueprint $table): void {
            $table->searchable(['title'], trigram: ['title']);
        });
        $drop = new Blueprint($this->getConnection('pre_'), 'tenant.posts', function (Blueprint $table): void {
            $table->dropSearchable(['title']);
        });

        $this->assertContains('create index "tenant_posts_title_trigram" on "tenant"."pre_posts" using gin ("title" gin_trgm_ops)', $create->toSql());
        $this->assertSame([
            'drop index "tenant"."tenant_posts_title_trigram"',
            'alter table "tenant"."pre_posts" drop column "search_vector"',
        ], $drop->toSql());
    }

    public function testVectorExpressionRejectsInvalidInput(): void
    {
        foreach ([[[], 'english'], [['title'], "english'); drop table users; --"], [['title' => 'E'], 'english'], [['ti"tle'], 'english']] as [$columns, $language]) {
            try {
                SearchableSchema::vectorExpression($columns, $language);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Get a PostgreSQL connection for compiling blueprints.
     */
    protected function getConnection(string $prefix = ''): Connection
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->allows('getTablePrefix')->returns($prefix);
        $connection->allows('getConfig')->with('prefix_indexes')->returns(null);
        $connection->allows('getSchemaGrammar')->returns(new PostgresGrammar($connection));
        $connection->allows('getSchemaBuilder')->returns(Double::for(PostgresBuilder::class)->passthru());

        return $connection;
    }
}
