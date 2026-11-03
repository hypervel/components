<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Http\Response;
use Hypervel\Telescope\Contracts\EntriesRepository;

class MailHtmlController
{
    /**
     * Get the HTML content of the given email.
     */
    public function show(EntriesRepository $storage, string $id): Response
    {
        return response($storage->find($id)->content['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => 'sandbox allow-popups allow-popups-to-escape-sandbox',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
