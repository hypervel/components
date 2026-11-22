<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap\Billing;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\Database\Schema\ForeignKeyDefinition;
use Hypervel\Support\Facades\Schema;

class BillingSchema
{
    /**
     * Register the billing migration macros.
     */
    public static function register(): void
    {
        Blueprint::macro('invoiceReference', function (): ColumnDefinition {
            return $this->string('invoice_reference');
        });

        Blueprint::macro('billingCustomer', function (): ForeignKeyDefinition {
            return $this->foreignUuid('billing_customer_id')->nullable(! $this->creating())->constrained('billing_customers');
        });

        Blueprint::macro('currencyAmount', function (string $column): ColumnDefinition {
            $amount = $this->decimal($column);

            $queued = array_any($this->getColumns(), static fn (ColumnDefinition $definition): bool => $definition->get('name') === 'currency');

            if (! $queued && ($this->creating() || ! Schema::hasColumn($this->getTable(), 'currency'))) {
                $this->char('currency', 3);
            }

            return $amount;
        });
    }
}
