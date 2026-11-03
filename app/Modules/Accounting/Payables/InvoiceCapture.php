<?php

namespace App\Modules\Accounting\Payables;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Stock\Application\ProcurementService;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * OCR-ready invoice capture: upload the document, an extractor (container-bound InvoiceExtractor, manual by default)
 * proposes the structured fields, the clerk confirms them, and a supplier invoice is recorded through ProcurementService.
 */
class InvoiceCapture
{
    use Scoped;

    public const FIELDS = ['supplier_id', 'reference', 'date', 'due_date', 'currency', 'net_total', 'tax_total', 'total', 'expense_lines', 'goods_receipt_ids'];

    public function __construct(TenantContext $ctx, private AuditTrail $audit, private ProcurementService $procurement)
    {
        $this->ctx = $ctx;
    }

    public function extractor(): InvoiceExtractor
    {
        return app()->bound(InvoiceExtractor::class) ? app(InvoiceExtractor::class) : new ManualExtractor;
    }

    public function schema(): array
    {
        return ['fields' => self::FIELDS, 'engine' => $this->extractor()->name()];
    }

    public function list(): array
    {
        return $this->table('supplier_invoice_captures')->orderByDesc('id')->limit(200)->get()->map(fn ($r) => $this->decode($r))->all();
    }

    public function show(int $id): array
    {
        return $this->decode($this->find($id));
    }

    public function upload(UploadedFile $file, bool $force = false): array
    {
        $sha = hash_file('sha256', $file->getRealPath());
        $dupe = $this->table('supplier_invoice_captures')->where('sha256', $sha)->first();
        if ($dupe && ! $force) {
            throw new DomainException("This document was already captured (capture #{$dupe->id}).", 'duplicate-document');
        }
        $h = $this->hotelId();
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?: 'invoice';
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $path = $file->storeAs("accounting/captures/$h", bin2hex(random_bytes(6)).'-'.$name, 'local');
        $extracted = [];
        $engine = $this->extractor();
        try {
            $extracted = $engine->extract(\Storage::disk('local')->path($path), $mime);
        } catch (\Throwable $e) {
            report($e); // a broken engine must never block manual capture
        }
        $id = DB::table('supplier_invoice_captures')->insertGetId($this->tenant() + [
            'status' => $extracted ? 'extracted' : 'uploaded', 'filename' => $name, 'mime' => $mime, 'size' => $file->getSize(), 'sha256' => $sha, 'path' => $path,
            'engine' => $engine->name(), 'extracted' => json_encode($extracted), 'fields' => json_encode($extracted), 'captured_by' => $this->actorId(), ...$this->stamp(),
        ]);
        $this->audit->record('invoice_captured', 'invoice_capture', $id, ['filename' => $name, 'sha256' => $sha, 'engine' => $engine->name()]);

        return $this->show($id);
    }

    public function file(int $id): array
    {
        $c = $this->find($id);

        return [\Storage::disk('local')->path($c->path), $c->filename, $c->mime];
    }

    /** Confirm the fields and record the supplier invoice. */
    public function convert(int $id, array $fields): array
    {
        $c = $this->find($id);
        if ($c->status === 'converted') {
            throw new DomainException('This capture is already converted.', 'already-converted');
        }
        $net = (int) $fields['net_total'];
        $tax = (int) ($fields['tax_total'] ?? 0);
        if (isset($fields['total']) && (int) $fields['total'] !== $net + $tax) {
            throw new DomainException('Total must equal net plus tax.', 'total-mismatch');
        }

        return DB::transaction(function () use ($c, $fields, $net, $tax) {
            $inv = $this->procurement->recordInvoice([
                'supplier_id' => (int) $fields['supplier_id'], 'reference' => $fields['reference'], 'date' => $fields['date'], 'due_date' => $fields['due_date'] ?? null,
                'net_total' => $net, 'tax_total' => $tax, 'goods_receipt_ids' => $fields['goods_receipt_ids'] ?? [], 'expense_lines' => $fields['expense_lines'] ?? [],
            ]);
            DB::table('supplier_invoice_captures')->where('id', $c->id)->update(['status' => 'converted', 'fields' => json_encode($fields), 'supplier_invoice_id' => $inv->id, 'updated_at' => now()]);
            $this->audit->record('invoice_capture_converted', 'invoice_capture', $c->id, ['supplier_invoice_id' => $inv->id, 'edited' => $this->diff(json_decode($c->extracted, true) ?: [], $fields)]);

            return ['capture' => $this->show($c->id), 'invoice' => $inv->toArray()];
        });
    }

    private function diff(array $suggested, array $final): array
    {
        return array_keys(array_filter(self::FIELDS ? array_flip(self::FIELDS) : [], fn ($_, $k) => ($suggested[$k] ?? null) != ($final[$k] ?? null), ARRAY_FILTER_USE_BOTH));
    }

    private function find(int $id): object
    {
        return $this->table('supplier_invoice_captures')->where('id', $id)->first() ?? throw new DomainException('Capture not found.', 'not-found');
    }

    private function decode(object $r): array
    {
        $a = (array) $r;
        $a['extracted'] = json_decode($r->extracted, true);
        $a['fields'] = json_decode($r->fields, true);
        unset($a['path']);

        return $a;
    }
}
