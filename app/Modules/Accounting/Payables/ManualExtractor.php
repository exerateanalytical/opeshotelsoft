<?php

namespace App\Modules\Accounting\Payables;

/** Default engine: extracts nothing; the clerk types the fields. */
class ManualExtractor implements InvoiceExtractor
{
    public function name(): string
    {
        return 'manual';
    }

    public function extract(string $absolutePath, string $mime): array
    {
        return [];
    }
}
