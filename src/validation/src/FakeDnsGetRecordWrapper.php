<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Egulias\EmailValidator\Validation\DNSGetRecordWrapper;
use Egulias\EmailValidator\Validation\DNSRecords;

class FakeDnsGetRecordWrapper extends DNSGetRecordWrapper
{
    /**
     * Get a synthetic DNS record for the given host.
     */
    public function getRecords(string $host, int $type): DNSRecords
    {
        return new DNSRecords([['type' => 'A']]);
    }
}
