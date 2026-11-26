<?php

declare(strict_types=1);

namespace SchemaModifiers;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Database\Schema\IndexDefinition;
use Hypervel\Support\Fluent;

function test(Blueprint $table, Fluent $fluent, ColumnDefinition|IndexDefinition $definition, ColumnDefinition|Fluent $attributes, ColumnDefinition $column): void
{
    $table->string('name')->nulable();
    $table->index('name')->algoritm('btree');
    $table->foreign('user_id')->references('id')->on('users')->onDelet('cascade');
    $table->foreignId('team_id')->referencesModelColumn('uuid')->tabel('teams');
    $definition->nulable();

    $table->string('email')->nullable()->unique();
    $table->index('email')->algorithm('btree');
    $table->foreignId('owner_id')->table('users')->constrained()->cascadeOnDelete();
    $table->string('label')->virtualAsJson('options->label');
    $table->string('code')->storedAsJson('options->code');
    $table->timestamp('touched_at')->onUpdate('CURRENT_TIMESTAMP');
    $table->foreign('parent_id')->references('id')->on('parents')->notValid();
    $table->vectorIndex('embedding')->operatorClass('vector_l2_ops');
    $table->string('title')->fluentMacro();
    $fluent->anything();
    $attributes->anything();

    // A subclass may declare the method.
    if (method_exists($column, 'extensionFlag')) {
        $column->extensionFlag();
    }

    $column->extensionFlag();
}
