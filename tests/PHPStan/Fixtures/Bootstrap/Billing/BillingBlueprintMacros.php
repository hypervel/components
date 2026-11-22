<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap\Billing;

use Hypervel\PHPStan\Schema\BlueprintMacroCall;
use Hypervel\PHPStan\Schema\BlueprintMacroExtension;

class BillingBlueprintMacros implements BlueprintMacroExtension
{
    /**
     * Get the macros registered by the billing package.
     */
    public function getMacros(): array
    {
        return array_fill_keys(['invoiceReference', 'billingCustomer', 'currencyAmount'], BillingSchema::class);
    }

    /**
     * Describe a billing macro's columns.
     */
    public function apply(string $macro, BlueprintMacroCall $call): void
    {
        if ($macro === 'invoiceReference') {
            $call->addColumn('string', 'invoice_reference');
        } elseif ($macro === 'billingCustomer') {
            $call->addColumn('uuid', 'billing_customer_id', nullable: ! $call->creating());
        } else {
            $call->addColumn('decimal', $call->name(0, 'column'));

            if ($call->hasColumn('currency') === false) {
                $call->addColumn('char', 'currency');
            }
        }
    }
}
