<?php

declare(strict_types=1);

namespace CallStack;

use Hypervel\Support\Facades\DB;

function enclosingCalls(): void
{
}

class TrackedOrder
{
    public string $status = 'new';

    public function save(): void
    {
    }
}

function keepsFramesAfterInvalidation(TrackedOrder $order): void
{
    DB::transaction(static function () use ($order): void {
        DB::usingConnection('sqlite', static function () use ($order): void {
            $order->status = 'paid';
            $order->save();

            enclosingCalls();
        });
    });
}
