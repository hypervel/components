<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap\Billing;

function invoiceReference(Invoice $invoice): int
{
    return $invoice->invoice_reference;
}

function misspelledInvoiceReference(Invoice $invoice): string
{
    return $invoice->invoice_refernece;
}
