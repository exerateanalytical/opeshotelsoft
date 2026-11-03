<?php

namespace App\Modules\Documents\Operations;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\IssuedDocuments;
use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * D-C operations and HR documents: GET /api/v1/documents/ops/{kind}/{id?} (?lang=fr|en).
 * Every kind lists the permissions that may print it (any of); sources are read through their own tenant-scoped models,
 * so another hotel's record is a 404. HR letters are versioned: POST .../{kind}/{id}/regenerate issues a new letter and
 * flips the old verify page to "replaced by ...". Withdrawn sources void their letters (Versions::voidAll).
 */
class OpsController
{
    private const STOCK = ['stock.view', 'stock.manage', 'purchasing.view', 'purchasing.manage', 'stockops.view', 'stockcontrol.view'];

    private const HR = ['hr.manage', 'hr.view'];

    public const KINDS = [
        'group-contract', 'rooming-list', 'police-register', 'lost-found-handin', 'lost-found-return', 'kot', 'pos-day-report',
        'goods-receipt', 'requisition', 'stock-issue', 'stock-transfer', 'supplier-return', 'stocktake-sheet', 'stocktake-variance', 'stock-valuation', 'rfq',
        'offer-letter', 'employment-attestation', 'salary-certificate', 'raise-letter', 'transfer-letter', 'warning-letter', 'termination-letter',
        'final-settlement', 'leave-slip', 'staff-id-card', 'payroll-journal',
    ];

    public function __construct(private StockSheets $stock, private FieldSheets $field, private HrSheets $hr, private DocumentRenderer $engine, private TenantContext $ctx) {}

    /** kind => [permissions (any), builder fn(?lang, hotelLang), versioned document type (HR letters only)] */
    private function kinds(Request $r, ?int $id): array
    {
        $q = $r->query();
        $need = fn () => $id ?? abort(404);
        $pos = ['pos.manage', 'pos.view'];

        return [
            'group-contract' => [['reservations.view', 'reservations.manage'], fn () => $this->field->groupContract($need())],
            'rooming-list' => [['reservations.view', 'reservations.manage'], fn () => $this->field->roomingList($need())],
            'police-register' => [['frontdesk.manage', 'frontdesk.view'], fn () => $this->field->policeRegister($q['from'] ?? null, $q['to'] ?? null)],
            'lost-found-handin' => [['housekeeping.manage', 'housekeeping.view', 'frontdesk.manage'], fn () => $this->field->lostFound($need(), 'handin')],
            'lost-found-return' => [['housekeeping.manage', 'housekeeping.view', 'frontdesk.manage'], fn () => $this->field->lostFound($need(), 'return')],
            'kot' => [$pos, fn () => $this->field->kot($need(), $q['fired_at'] ?? null)],
            'pos-day-report' => [$pos, fn () => $this->field->posDayReport($q)],
            'goods-receipt' => [self::STOCK, fn () => $this->stock->goodsReceipt($need())],
            'requisition' => [self::STOCK, fn () => $this->stock->requisition($need())],
            'stock-issue' => [self::STOCK, fn () => $this->stock->stockIssue($need())],
            'stock-transfer' => [self::STOCK, fn () => $this->stock->stockTransfer($need())],
            'supplier-return' => [['purchasing.view', 'purchasing.manage', 'payables.view', 'payables.manage', 'stock.manage'], fn () => $this->stock->supplierReturn($need())],
            // Counters see the blind sheet only; expected quantities and variances need stocktake.review.
            'stocktake-sheet' => [['stocktake.count', 'stocktake.review'], fn () => $this->stock->stocktakeSheet($need(),
                filter_var($q['blind'] ?? false, FILTER_VALIDATE_BOOLEAN) || ! $this->can($r, ['stocktake.review']))],
            'stocktake-variance' => [['stocktake.review'], fn () => $this->stock->stocktakeVariance($need())],
            'stock-valuation' => [[...self::STOCK, 'accounting.view'], fn () => $this->stock->valuation($q)],
            'rfq' => [['purchasing.view', 'purchasing.manage', 'stockops.view'], fn () => $this->stock->rfq($need(), isset($q['supplier']) ? (int) $q['supplier'] : null)],
            'offer-letter' => [['hr.manage'], fn ($l, $h) => $this->hr->offerLetter($need(), $l, $h), 'offer_letter'],
            'employment-attestation' => [['hr.manage', 'payroll.manage'], fn ($l, $h) => $this->hr->attestation($need(), $l, $h), 'employment_attestation'],
            'salary-certificate' => [['hr.manage', 'payroll.manage'], fn ($l, $h) => $this->hr->salaryCertificate($need(), $l, $h), 'salary_certificate'],
            'raise-letter' => [['hr.manage'], fn ($l, $h) => $this->hr->raiseLetter($need(), $l, $h), 'raise_letter'],
            'transfer-letter' => [['hr.manage'], fn ($l, $h) => $this->hr->transferLetter($need(), $l, $h), 'transfer_letter'],
            'warning-letter' => [['people.discipline'], fn ($l, $h) => $this->hr->warningLetter($need(), $l, $h), 'warning_letter'],
            'termination-letter' => [['people.exit', 'hr.manage'], fn ($l, $h) => $this->hr->terminationLetter($need(), $l, $h), 'termination_letter'],
            'final-settlement' => [['people.exit', 'hr.manage'], fn ($l, $h) => $this->hr->settlement($need(), $l, $h)],
            'leave-slip' => [self::HR, fn ($l, $h) => $this->hr->leaveSlip($need(), $l, $h)],
            'staff-id-card' => [self::HR, fn ($l, $h) => $this->hr->idCard($need(), $l, $h), 'staff_id_card'],
            'payroll-journal' => [[...self::HR, 'payroll.manage'], fn ($l, $h) => $this->hr->payrollJournal($need(), $l, $h)],
        ];
    }

    public function show(Request $request, string $kind, ?int $id = null)
    {
        return $this->respond($request, $kind, $id, false);
    }

    /** POST {kind}/{id}/regenerate {reason}: a new version of an HR letter; the previous verify page says "replaced by". */
    public function regenerate(Request $request, string $kind, int $id)
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        abort_unless($this->can($request, ['documents.ops.regenerate']), 403, 'You are not allowed to regenerate this document.');

        return $this->respond($request, $kind, $id, true);
    }

    private function respond(Request $request, string $kind, ?int $id, bool $regen)
    {
        $def = $this->kinds($request, $id)[$kind] ?? abort(404);
        abort_unless($this->can($request, $def[0]), 403, 'You are not allowed to print this document.');
        $docType = $def[2] ?? null;
        abort_if($regen && ! $docType, 422, 'This document cannot be regenerated.');

        $hotel = $this->ctx->hotelId() ? Hotel::find($this->ctx->hotelId()) : null;
        $hl = $hotel && str_starts_with((string) $hotel->locale, 'en') ? 'en' : 'fr';
        $explicit = in_array($request->query('lang'), ['fr', 'en'], true) ? $request->query('lang') : null;

        $version = 1;
        $old = null;
        if ($docType) {
            [$sourceType, $count, $old] = Versions::current($docType, $id);
            abort_if($regen && ! $old, 422, 'Nothing has been issued yet: print the document first.');
            $version = $regen ? $count + 1 : max(1, $count);
            $this->hr->suffix = $version > 1 ? '-R'.$version : '';
        }

        $b = ($def[1])($explicit, $hl);
        $lang = $b['lang'] ?? $explicit ?? $hl;
        $title = is_array($b['title']) ? $b['title'][$lang === 'en' ? 1 : 0] : (string) $b['title'];
        $sourceType = $b['source_type'];
        if ($docType && $version > 1) {
            $sourceType = $docType.'#v'.$version;
        }

        $doc = $this->engine->render($b['type'], $b['data'], [
            'view' => $b['view'], 'lang' => $lang, 'size' => $b['size'], 'hotel' => $hotel, 'number' => $b['number'], 'title' => $title,
            'source_type' => $sourceType, 'source_id' => $b['source_id'] ?? null, 'amount' => $b['amount'] ?? null, 'currency' => $b['currency'] ?? null,
            'filename' => $kind.'-'.$b['number'],
        ]);
        if ($regen && $old && $doc->record) {
            app(IssuedDocuments::class)->replaceSource($docType, $old->source_type, (int) $id, $doc->record, (string) $request->input('reason'));
        }

        return $this->engine->response($doc);
    }

    private function can(Request $request, array $perms): bool
    {
        $u = $request->user();
        foreach ($perms as $p) {
            try {
                if ($u->can($p)) {
                    return true;
                }
            } catch (\Throwable) {
                // permission not defined in this install: ignore
            }
        }

        return false;
    }
}
