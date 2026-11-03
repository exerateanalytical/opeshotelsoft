<?php

namespace App\Modules\Documents\Operations;

use App\Modules\Stock\Control\Application\ValuationService;
use App\Modules\Stock\Domain\GoodsReceipt;
use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\PurchaseRequisition;
use App\Modules\Stock\Domain\PurchaseRequisitionLine;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\StockMovement;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Stock\Domain\SupplierReturn;
use App\Modules\Stock\Operations\Application\RfqService;
use App\Modules\Stock\Operations\Application\StocktakeService;
use App\Modules\Stock\Operations\Domain\Rfq;
use App\Modules\Stock\Operations\Domain\RfqSupplier;
use App\Modules\Stock\Operations\Domain\Stocktake;
use App\Modules\Stock\Operations\Domain\StocktakeLine;
use App\Platform\Domain\DomainException;

/** D-C stock and purchasing documents: rows 42 to 48 of the catalog. */
class StockSheets
{
    use SheetHelpers;

    private function items(iterable $ids): \Illuminate\Support\Collection
    {
        return StockItem::whereIn('id', collect($ids)->filter()->unique())->get()->keyBy('id');
    }

    private function storeName(?int $id): ?string
    {
        return $id ? Store::find($id)?->name : null;
    }

    /** Row 42: goods receipt note (bon de réception). */
    public function goodsReceipt(int $id): array
    {
        $g = GoodsReceipt::with('lines')->findOrFail($id);
        $items = $this->items($g->lines->pluck('stock_item_id'));
        $rows = [];
        $net = 0;
        foreach ($g->lines as $l) {
            $i = $items[$l->stock_item_id] ?? null;
            $value = (int) round($l->quantity * $l->unit_price);
            $net += $value;
            $rows[] = ['code' => $i?->code, 'name' => $i?->name, 'unit' => $i?->purchase_unit ?: $i?->unit, 'qty' => $l->quantity, 'price' => (int) $l->unit_price,
                'vat' => $l->vat_rate, 'value' => $value];
        }
        $po = $g->purchase_order_id ? PurchaseOrder::find($g->purchase_order_id) : null;
        $sheet = [
            'title' => ['Bon de réception', 'Goods receipt note'],
            'meta' => [
                ['Fournisseur', 'Supplier', Supplier::find($g->supplier_id)?->name], ['Bon de commande', 'Purchase order', $po?->number],
                ['Magasin', 'Store', $this->storeName($g->store_id)], ['Date de réception', 'Received on', $this->date($g->date)],
                ['Bon de livraison', 'Delivery note', $g->delivery_note], ['Réceptionné par', 'Received by', $this->userName($g->received_by)],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit'), self::col('qty', 'Qté reçue', 'Qty received', 'qty'),
                self::col('price', 'Prix unitaire', 'Unit price', 'money'), self::col('vat', 'TVA %', 'VAT %', 'qty'), self::col('value', 'Valeur HT', 'Net value', 'money'),
            ], 'rows' => $rows, 'totals' => [['Total HT', 'Net total', $net]]]],
            'sign' => [['Magasinier', 'Storekeeper'], ['Livreur / fournisseur', 'Supplier / driver'], ['Contrôle (responsable)', 'Checked by (manager)']],
        ];

        return $this->result('goods_receipt', $sheet, 'BR-'.$g->number, $g->id, ['amount' => $net, 'currency' => 'XAF']);
    }

    /** Row 43: purchase requisition (department request). */
    public function requisition(int $id): array
    {
        $r = PurchaseRequisition::findOrFail($id);
        $lines = PurchaseRequisitionLine::where('purchase_requisition_id', $r->id)->orderBy('id')->get();
        $items = $this->items($lines->pluck('stock_item_id'));
        $rows = [];
        $est = 0;
        foreach ($lines as $l) {
            $i = $items[$l->stock_item_id] ?? null;
            $value = (int) round($l->quantity * (float) ($i?->avg_cost ?? 0));
            $est += $value;
            $rows[] = ['code' => $i?->code, 'name' => $i?->name, 'unit' => $i?->unit, 'qty' => $l->quantity, 'supplier' => $l->supplier_id ? Supplier::find($l->supplier_id)?->name : '', 'value' => $value];
        }
        $sheet = [
            'title' => ['Demande d\'achat (réquisition)', 'Purchase requisition'],
            'meta' => [
                ['Service', 'Department', $r->department], ['Magasin', 'Store', $this->storeName($r->store_id)], ['Statut', 'Status', $r->status],
                ['Souhaité pour le', 'Needed by', $this->date($r->needed_by)], ['Demandé par', 'Requested by', $this->userName($r->requested_by)],
                ['Approuvé par', 'Approved by', $this->userName($r->approved_by)], ['Notes', 'Notes', $r->notes],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit'), self::col('qty', 'Quantité', 'Quantity', 'qty'),
                self::col('supplier', 'Fournisseur', 'Supplier'), self::col('value', 'Coût estimé', 'Estimated cost', 'money'),
            ], 'rows' => $rows, 'totals' => [['Coût estimé total', 'Total estimated cost', $est]]]],
            'sign' => [['Demandeur', 'Requester'], ['Chef de service', 'Head of department'], ['Direction / achats', 'Management / purchasing']],
        ];

        return $this->result('requisition', $sheet, 'REQ-'.$r->number, $r->id);
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: int} movements of one note (same reference, store and date) and the first id */
    private function movementGroup(int $id, string $kind): array
    {
        $m = StockMovement::where('kind', $kind)->findOrFail($id);
        $group = $m->reference
            ? StockMovement::where('kind', $kind)->where('reference', $m->reference)->where('from_store_id', $m->from_store_id)->whereDate('date', $m->date)->orderBy('id')->get()
            : collect([$m]);

        return [$group, (int) $group->min('id')];
    }

    /** Row 43: stock issue note. */
    public function stockIssue(int $id): array
    {
        [$g, $first] = $this->movementGroup($id, 'issue');
        $m = $g->first();
        $items = $this->items($g->pluck('stock_item_id'));
        $rows = $g->map(fn ($x) => ['code' => $items[$x->stock_item_id]->code ?? '', 'name' => $items[$x->stock_item_id]->name ?? '', 'unit' => $items[$x->stock_item_id]->unit ?? '',
            'qty' => (float) $x->quantity, 'cost' => (float) $x->unit_cost, 'value' => (int) $x->value])->all();
        $total = (int) $g->sum('value');
        $sheet = [
            'title' => ['Bon de sortie de stock', 'Stock issue note'],
            'meta' => [
                ['Magasin', 'Store', $this->storeName($m->from_store_id)], ['Service destinataire', 'Issued to department', $m->department], ['Date', 'Date', $this->date($m->date)],
                ['Référence', 'Reference', $m->reference], ['Motif', 'Reason', $m->reason], ['Sorti par', 'Issued by', $this->userName($m->user_id)],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit'), self::col('qty', 'Quantité', 'Quantity', 'qty'),
                self::col('cost', 'Coût unitaire', 'Unit cost', 'qty'), self::col('value', 'Valeur', 'Value', 'money'),
            ], 'rows' => $rows, 'totals' => [['Valeur totale', 'Total value', $total]]]],
            'sign' => [['Magasinier', 'Storekeeper'], ['Bénéficiaire', 'Recipient'], ['Chef de service', 'Head of department']],
        ];

        return $this->result('stock_issue', $sheet, 'SI-'.str_pad((string) $first, 6, '0', STR_PAD_LEFT), $first, ['amount' => $total, 'currency' => 'XAF']);
    }

    /** Row 44: stock transfer note. */
    public function stockTransfer(int $id): array
    {
        [$g, $first] = $this->movementGroup($id, 'transfer');
        $m = $g->first();
        $items = $this->items($g->pluck('stock_item_id'));
        $rows = $g->map(fn ($x) => ['code' => $items[$x->stock_item_id]->code ?? '', 'name' => $items[$x->stock_item_id]->name ?? '', 'unit' => $items[$x->stock_item_id]->unit ?? '',
            'qty' => (float) $x->quantity, 'cost' => (float) $x->unit_cost, 'value' => (int) $x->value])->all();
        $total = (int) $g->sum('value');
        $sheet = [
            'title' => ['Bon de transfert de stock', 'Stock transfer note'],
            'meta' => [
                ['Magasin d\'origine', 'From store', $this->storeName($m->from_store_id)], ['Magasin de destination', 'To store', $this->storeName($m->to_store_id)],
                ['Date', 'Date', $this->date($m->date)], ['Référence', 'Reference', $m->reference], ['Motif', 'Reason', $m->reason], ['Transféré par', 'Transferred by', $this->userName($m->user_id)],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit'), self::col('qty', 'Quantité', 'Quantity', 'qty'),
                self::col('cost', 'Coût unitaire', 'Unit cost', 'qty'), self::col('value', 'Valeur', 'Value', 'money'),
            ], 'rows' => $rows, 'totals' => [['Valeur totale', 'Total value', $total]]]],
            'sign' => [['Expéditeur (magasin d\'origine)', 'Sender (origin store)'], ['Transporteur', 'Carrier'], ['Réceptionnaire (destination)', 'Receiver (destination store)']],
        ];

        return $this->result('stock_transfer', $sheet, 'TR-'.str_pad((string) $first, 6, '0', STR_PAD_LEFT), $first, ['amount' => $total, 'currency' => 'XAF']);
    }

    /** Row 45: supplier return / debit note. */
    public function supplierReturn(int $id): array
    {
        $r = SupplierReturn::findOrFail($id);
        $grn = GoodsReceipt::find($r->goods_receipt_id);
        $rows = collect($r->lines ?: [])->map(fn ($l) => ['name' => $l['item'] ?? '', 'qty' => $l['quantity'] ?? 0, 'price' => (int) ($l['unit_price'] ?? 0), 'value' => (int) ($l['value'] ?? 0)])->all();
        $sheet = [
            'title' => ['Bon de retour fournisseur / note de débit', 'Supplier return / debit note'],
            'meta' => [
                ['Fournisseur', 'Supplier', Supplier::find($r->supplier_id)?->name], ['Bon de réception', 'Goods receipt', $grn?->number], ['Date', 'Date', $this->date($r->date)],
                ['Motif du retour', 'Reason for return', $r->reason], ['Établi par', 'Prepared by', $this->userName($r->user_id)],
            ],
            'blocks' => [
                ['type' => 'table', 'cols' => [self::col('name', 'Article', 'Item'), self::col('qty', 'Qté retournée', 'Qty returned', 'qty'), self::col('price', 'Prix unitaire', 'Unit price', 'money'), self::col('value', 'Valeur', 'Value', 'money')],
                    'rows' => $rows, 'totals' => [['Total HT', 'Net total', (int) $r->net_total], ['TVA', 'VAT', (int) $r->tax_total], ['Total TTC (avoir attendu)', 'Total incl. VAT (credit expected)', (int) $r->total]]],
            ],
            'note' => ['Nous vous retournons les marchandises ci-dessus et vous demandons de nous adresser un avoir du montant indiqué.', 'We are returning the goods above and ask you to issue a credit note for the amount shown.'],
            'sign' => [['Établi par', 'Prepared by'], ['Fournisseur (réception)', 'Supplier (receipt)']],
        ];

        return $this->result('supplier_return', $sheet, 'RTV-'.$r->number, $r->id, ['amount' => (int) $r->total, 'currency' => 'XAF']);
    }

    /** Row 46: count sheet, optionally blind (no expected quantities). */
    public function stocktakeSheet(int $id, bool $blindParam): array
    {
        $st = Stocktake::findOrFail($id);
        $blind = $blindParam || $st->blind;
        $lines = StocktakeLine::where('stocktake_id', $st->id)->orderBy('id')->get();
        $items = $this->items($lines->pluck('stock_item_id'));
        $rows = $lines->map(fn ($l) => ['code' => $items[$l->stock_item_id]->code ?? '', 'name' => $items[$l->stock_item_id]->name ?? '', 'unit' => $items[$l->stock_item_id]->unit ?? '',
            'expected' => $blind ? '' : (float) $l->expected, 'c1' => '', 'c2' => '', 'obs' => ''])->all();
        $cols = [self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit')];
        if (! $blind) {
            $cols[] = self::col('expected', 'Théorique', 'Expected', 'qty');
        }
        array_push($cols, self::col('c1', 'Comptage 1', 'Count 1'), self::col('c2', 'Recomptage', 'Recount'), self::col('obs', 'Observations', 'Remarks'));
        $sheet = [
            'title' => $blind ? ['Fiche de comptage (à l\'aveugle)', 'Stock count sheet (blind)'] : ['Fiche de comptage de stock', 'Stock count sheet'],
            'meta' => [
                ['Inventaire', 'Stocktake', $st->number], ['Magasin', 'Store', $this->storeName($st->store_id)], ['Date', 'Date', $this->date($st->date)],
                ['Mode', 'Mode', $blind ? 'Blind / Aveugle' : 'Standard'],
            ],
            'blocks' => [['type' => 'table', 'cols' => $cols, 'rows' => $rows]],
            'note' => $blind ? ['Les quantités théoriques ne sont pas imprimées : comptez ce que vous voyez.', 'Expected quantities are not printed: count what you see.'] : null,
            'sign' => [['Compteur 1', 'Counter 1'], ['Compteur 2 (recomptage)', 'Counter 2 (recount)'], ['Responsable', 'Supervisor']],
        ];
        $sheet = array_filter($sheet, fn ($v) => $v !== null);

        return $this->result('stock_count_sheet', $sheet, 'CNT-'.$st->number.($blind ? '-B' : ''), $st->id, ['source_type' => $blind ? 'stocktake_blind' : 'stocktake', 'size' => 'a4']);
    }

    /** Row 46: variance report of a stocktake. */
    public function stocktakeVariance(int $id): array
    {
        $st = Stocktake::findOrFail($id);
        $rev = app(StocktakeService::class)->review($st);
        $rows = collect($rev['lines'])->map(fn ($l) => $l + ['c1' => $l['count_1'], 'c2' => $l['count_2']])->all();
        $total = (int) $rev['variance_value'];
        $sheet = [
            'title' => ['Rapport d\'écarts d\'inventaire', 'Stocktake variance report'],
            'meta' => [
                ['Inventaire', 'Stocktake', $st->number], ['Magasin', 'Store', $this->storeName($st->store_id)], ['Date', 'Date', $this->date($st->date)], ['Statut', 'Status', $st->status],
                ['Tolérance', 'Tolerance', $st->tolerance_pct.' % / '.$st->tolerance_qty], ['Lancé par', 'Started by', $this->userName($st->started_by)], ['Approuvé par', 'Approved by', $this->userName($st->approved_by)],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit'), self::col('expected', 'Théorique', 'Expected', 'qty'),
                self::col('c1', 'Compté 1', 'Count 1', 'qty'), self::col('c2', 'Compté 2', 'Count 2', 'qty'), self::col('final', 'Retenu', 'Final', 'qty'),
                self::col('variance_qty', 'Écart qté', 'Qty variance', 'qty'), self::col('variance_value', 'Écart valeur', 'Value variance', 'money'),
            ], 'rows' => $rows, 'totals' => [['Écart total en valeur', 'Total value variance', $total]]]],
            'sign' => [['Responsable de l\'inventaire', 'Stocktake lead'], ['Approbateur', 'Approver']],
        ];

        return $this->result('stock_variance_report', $sheet, 'VAR-'.$st->number, $st->id, ['size' => 'a4-landscape', 'amount' => abs($total), 'currency' => 'XAF']);
    }

    /** Row 47: valuation report as of a date (WAC and FIFO side by side). */
    public function valuation(array $q): array
    {
        $asOf = $q['as_of'] ?? ($this->hotel()?->business_date?->toDateString() ?? now()->toDateString());
        $storeId = isset($q['store_id']) ? (int) $q['store_id'] : null;
        $v = app(ValuationService::class)->asOf($asOf, $storeId, $q['category'] ?? null);
        $sheet = [
            'title' => ['Valorisation du stock', 'Stock valuation report'],
            'meta' => [['Au', 'As of', $this->date($asOf)], ['Magasin', 'Store', $storeId ? $this->storeName($storeId) : 'All stores / Tous'], ['Catégorie', 'Category', $q['category'] ?? null]],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('category', 'Catégorie', 'Category'), self::col('unit', 'Unité', 'Unit'),
                self::col('quantity', 'Quantité', 'Quantity', 'qty'), self::col('wac_unit_cost', 'CMUP unitaire', 'WAC unit', 'qty'), self::col('wac_value', 'Valeur CMUP', 'WAC value', 'money'),
                self::col('fifo_value', 'Valeur PEPS', 'FIFO value', 'money'),
            ], 'rows' => $v['rows'], 'foot' => ['name' => 'TOTAL', 'wac_value' => $v['totals']['wac_value'], 'fifo_value' => $v['totals']['fifo_value']]]],
            'note' => ['CMUP : coût moyen unitaire pondéré. PEPS : premier entré, premier sorti. Quantités reconstituées depuis le journal des mouvements.', 'WAC: weighted average cost. FIFO: first in, first out. Quantities replayed from the movement ledger.'],
        ];

        return $this->result('stock_valuation', $sheet, 'VAL-'.str_replace('-', '', $asOf).($storeId ? '-S'.$storeId : ''), null,
            ['size' => 'a4-landscape', 'amount' => $v['totals']['wac_value'], 'currency' => 'XAF']);
    }

    /** Row 48: supplier-facing request for quotation. Prices are blank for the supplier to fill. */
    public function rfq(int $id, ?int $supplierId): array
    {
        $rfq = Rfq::findOrFail($id);
        if (! $supplierId) {
            $supplierId = (int) RfqSupplier::where('rfq_id', $rfq->id)->orderBy('id')->value('supplier_id');
        }
        if (! $supplierId) {
            throw new DomainException('This RFQ has no supplier yet.', 'rfq-no-supplier');
        }
        $s = app(RfqService::class)->supplierSheet($rfq, $supplierId);
        $sup = Supplier::find($supplierId);
        $rows = collect($s['lines'])->map(fn ($l) => ['code' => $l['item_code'], 'name' => $l['item_name'], 'unit' => $l['unit'], 'qty' => $l['quantity'], 'notes' => $l['notes'], 'price' => '', 'lead' => ''])->all();
        $sheet = [
            'title' => ['Demande de cotation', 'Request for quotation'],
            'meta' => [
                ['Objet', 'Subject', $rfq->title], ['Fournisseur', 'Supplier', $sup?->name], ['Contact', 'Contact', $sup?->contact_name],
                ['Réponse souhaitée avant le', 'Please reply by', $this->date($rfq->due_date)], ['Notes', 'Notes', $rfq->notes],
            ],
            'blocks' => [['type' => 'table', 'cols' => [
                self::col('code', 'Code', 'Code'), self::col('name', 'Article', 'Item'), self::col('unit', 'Unité', 'Unit'), self::col('qty', 'Quantité', 'Quantity', 'qty'),
                self::col('notes', 'Précisions', 'Specifications'), self::col('price', 'Prix unitaire proposé', 'Unit price offered'), self::col('lead', 'Délai (jours)', 'Lead time (days)'),
            ], 'rows' => $rows]],
            'note' => ['Merci de nous retourner vos prix unitaires hors taxes, conditions de paiement et délais de livraison avant la date indiquée. Cette demande ne constitue pas une commande.', 'Please return your unit prices excluding tax, payment terms and delivery lead times before the date shown. This request is not a purchase order.'],
            'sign' => [['Cachet et signature du fournisseur', 'Supplier stamp and signature'], ['Acheteur', 'Buyer']],
        ];

        return $this->result('rfq', $sheet, 'DC-'.$rfq->number.'-'.($sup?->code ?: $supplierId), $rfq->id, ['source_type' => 'rfq_supplier_'.$supplierId, 'size' => 'a4']);
    }
}
