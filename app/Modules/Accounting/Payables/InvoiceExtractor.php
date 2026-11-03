<?php

namespace App\Modules\Accounting\Payables;

/** Pluggable field extraction from an invoice document (bind your own implementation in the container; no external service is built in). */
interface InvoiceExtractor
{
    public function name(): string;

    /**
     * @return array{supplier_name?:string, supplier_id?:int, reference?:string, date?:string, due_date?:string, currency?:string, net_total?:int, tax_total?:int, total?:int, expense_lines?:array}
     */
    public function extract(string $absolutePath, string $mime): array;
}
