<?php

declare(strict_types=1);

namespace Hypervel\Types\Database\Schema;

use Hypervel\Database\Schema\Blueprint;

use function PHPStan\Testing\assertType;

function testIndexDefinitionsUseConcreteTypes(Blueprint $table): void
{
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->primary('id'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->unique('email'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->index('name'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->fullText('body'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->spatialIndex('location'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->vectorIndex('embedding'));
    assertType('Hypervel\Database\Schema\IndexDefinition', $table->rawIndex('(lower(email))', 'users_email_lower_index'));
    assertType(
        'Hypervel\Database\Schema\IndexDefinition',
        $table->index('archived_at')->whereNotNull('archived_at'),
    );
}
