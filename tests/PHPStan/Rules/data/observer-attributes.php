<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\ObserverAttributes;

use Acme\Audit\AuditEntry;
use Acme\Ledger\LedgerEntry;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\ServiceProvider;

class Invoice extends Model
{
}

class Payment extends Model
{
}

class Refund extends Model
{
    public static function observe(object|array|string $classes): void
    {
    }
}

class InvoiceObserver
{
}

class PaymentObserver
{
}

class ObserverServiceProvider extends ServiceProvider
{
    private const array PAYMENT_OBSERVERS = [PaymentObserver::class, InvoiceObserver::class];

    public function register(): void
    {
        Invoice::observe(InvoiceObserver::class);
    }

    public function boot(): void
    {
        Payment::observe([PaymentObserver::class, InvoiceObserver::class]);
        Payment::observe(self::PAYMENT_OBSERVERS);
        Invoice::observe(new InvoiceObserver);
        Invoice::observe($this->observers());
        Refund::observe(InvoiceObserver::class);
        AuditEntry::observe(InvoiceObserver::class);
        LedgerEntry::observe(InvoiceObserver::class);

        if ($this->app->runningInConsole()) {
            Invoice::observe(InvoiceObserver::class);
        }

        $this->app->booted(function (): void {
            Invoice::observe(InvoiceObserver::class);
        });
    }

    public function observers(): array
    {
        return [InvoiceObserver::class];
    }
}

class Billing
{
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);
    }
}
