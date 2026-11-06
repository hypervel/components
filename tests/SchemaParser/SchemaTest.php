<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser;

use Hypervel\SchemaParser\Column;
use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Domain;
use Hypervel\SchemaParser\EnumType;
use Hypervel\SchemaParser\Index;
use Hypervel\SchemaParser\IndexColumn;
use Hypervel\SchemaParser\Issue;
use Hypervel\SchemaParser\Schema;
use Hypervel\SchemaParser\Table;
use Hypervel\SchemaParser\View;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class SchemaTest extends TestCase
{
    public function testQualifiedLookupsPreserveDotsAndCase(): void
    {
        $first = new Table('b.c', [], 'a');
        $second = new Table('c', [], 'a.b');
        $quoted = new Table('Users', [], 'public');
        $schema = new Schema([$first, $second, $quoted]);

        $this->assertSame($first, $schema->table('b.c', 'a'));
        $this->assertSame($second, $schema->table('c', 'a.b'));
        $this->assertSame($quoted, $schema->table('Users'));
        $this->assertNull($schema->table('users'));
        $this->assertNull($schema->table('b.c', 'archive'));
        $this->assertNull($schema->table('missing'));
    }

    public function testAmbiguousUnqualifiedLookupRequiresTheSchema(): void
    {
        $public = new Table('events', [], 'public');
        $archive = new Table('events', [], 'archive');
        $schema = new Schema([$public, $archive]);

        $this->assertSame($archive, $schema->table('events', 'archive'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The name 'events' is ambiguous across schemas: 'public', 'archive'.");

        $schema->table('events');
    }

    public function testUnspecifiedSchemaIsNotAssumedToBePublic(): void
    {
        $table = new Table('events', []);
        $schema = new Schema([$table]);

        $this->assertSame($table, $schema->table('events'));
        $this->assertNull($schema->table('events', 'public'));
    }

    public function testViewsAndDeclaredTypesHaveIndependentLookups(): void
    {
        $view = new View('status', 'select 1', 'reporting');
        $enum = new EnumType('status', ['pending', 'complete'], 'public');
        $domain = new Domain('status', new ColumnType('text'), 'archive');
        $schema = new Schema(views: [$view], enums: [$enum], domains: [$domain]);

        $this->assertSame($view, $schema->view('status', 'reporting'));
        $this->assertSame($enum, $schema->enum('status', 'public'));
        $this->assertSame($domain, $schema->domain('status', 'archive'));
        $this->assertNull($schema->table('status'));
        $this->assertNull($schema->enum('status', 'archive'));
    }

    public function testColumnAndPrimaryKeyLookupsPreserveDeclarationOrder(): void
    {
        $name = new Column('Name', new ColumnType('text'));
        $id = new Column('id', new ColumnType('integer'), nullable: false);
        $secondary = new Index('by_name', [new IndexColumn('Name')]);
        $primary = new Index('primary', [new IndexColumn('id')], unique: true, primary: true);
        $table = new Table('users', [$name, $id], indexes: [$secondary, $primary]);

        $this->assertSame([$name, $id], $table->columns);
        $this->assertSame($name, $table->column('Name'));
        $this->assertNull($table->column('name'));
        $this->assertSame($primary, $table->primaryKey());
        $this->assertNull((new Table('without_key', []))->primaryKey());
    }

    public function testAnIncompleteTableDoesNotMakeOtherTableNamesUnknown(): void
    {
        $users = new Table('users', []);
        $search = new Table('search', [], issues: [new Issue('Virtual table columns are unknown.', 7)]);
        $schema = new Schema([$users, $search]);

        $this->assertFalse($schema->isComplete());
        $this->assertFalse($search->isComplete());
        $this->assertTrue($users->isComplete());
        $this->assertSame([], $schema->issues);
        $this->assertNull($schema->table('missing'));

        $unknownStatement = new Issue('An unread statement may define additional relations.', 11);
        $uncertainSchema = new Schema([$users], issues: [$unknownStatement]);

        $this->assertFalse($uncertainSchema->isComplete());
        $this->assertSame([$unknownStatement], $uncertainSchema->issues);
        $this->assertSame($users, $uncertainSchema->table('users'));
    }
}
