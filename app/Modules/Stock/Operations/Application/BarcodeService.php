<?php

namespace App\Modules\Stock\Operations\Application;

use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Control\Domain\StockLot;
use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Operations\Domain\ItemBarcode;
use App\Platform\Domain\DomainException;

/**
 * Printable code values and scan resolution. Code values are what the label printer puts in a QR or Code 128:
 * ITM:<item code>, LOT:<lot id>, PO:<po number>. External barcodes (EAN on the carton) are registered per item.
 */
class BarcodeService
{
    public function __construct(private ProcurementService $procurement) {}

    public function itemCode(StockItem $item): array
    {
        return ['value' => 'ITM:'.$item->code, 'label' => $item->code.' '.$item->name, 'external' => ItemBarcode::where('stock_item_id', $item->id)->pluck('code')->all()];
    }

    public function lotCode(StockLot $lot): array
    {
        $item = StockItem::find($lot->stock_item_id);

        return ['value' => 'LOT:'.$lot->id, 'label' => ($item?->code).' '.$lot->lot_number.($lot->expiry_date ? ' '.$lot->expiry_date->toDateString() : '')];
    }

    public function register(StockItem $item, string $code): ItemBarcode
    {
        $code = trim($code);
        if ($code === '' || preg_match('/^(ITM|LOT|PO):/', $code)) {
            throw new DomainException('Invalid barcode.', 'barcode-invalid');
        }
        $existing = ItemBarcode::where('code', $code)->first();
        if ($existing && $existing->stock_item_id !== $item->id) {
            throw new DomainException('This barcode already belongs to another item.', 'barcode-duplicate');
        }

        return $existing ?? ItemBarcode::create(['stock_item_id' => $item->id, 'code' => $code]);
    }

    /** @return array{type:string,item?:array,lot?:array,po?:array} */
    public function resolve(string $code): array
    {
        $code = trim($code);
        if (str_starts_with($code, 'PO:')) {
            $po = PurchaseOrder::where('number', substr($code, 3))->first();
            if ($po) {
                return ['type' => 'po', 'po' => $po->only(['id', 'number', 'status', 'supplier_id', 'store_id'])];
            }
        } elseif (str_starts_with($code, 'LOT:')) {
            $lot = StockLot::find((int) substr($code, 4));
            if ($lot) {
                return ['type' => 'lot', 'lot' => $lot->only(['id', 'lot_number', 'expiry_date', 'quantity_remaining', 'store_id']), 'item' => $this->itemRow(StockItem::findOrFail($lot->stock_item_id))];
            }
        } else {
            $item = str_starts_with($code, 'ITM:') ? StockItem::where('code', substr($code, 4))->first() : null;
            $item ??= ($b = ItemBarcode::where('code', $code)->first()) ? StockItem::find($b->stock_item_id) : null;
            $item ??= StockItem::where('code', $code)->first();
            if ($item) {
                return ['type' => 'item', 'item' => $this->itemRow($item)];
            }
        }
        throw new DomainException('Unknown code.', 'scan-unknown', 404);
    }

    /** The item behind a scanned item or lot code, or fail. */
    public function itemFor(string $code): StockItem
    {
        $r = $this->resolve($code);
        if (! isset($r['item'])) {
            throw new DomainException('This code is not an item or lot.', 'scan-not-item');
        }

        return StockItem::findOrFail($r['item']['id']);
    }

    /** Receive the scanned quantity against the first open line of the PO for that item. */
    public function receiveByScan(PurchaseOrder $po, string $code, float $quantity, ?string $deliveryNote = null)
    {
        $item = $this->itemFor($code);
        $line = $po->lines()->where('stock_item_id', $item->id)->whereRaw('quantity - received_quantity > 0.0001')->orderBy('id')->first()
            ?? throw new DomainException("{$item->name} is not (or no longer) expected on {$po->number}.", 'scan-not-on-po');

        return $this->procurement->receive($po, [['purchase_order_line_id' => $line->id, 'quantity' => $quantity]], $deliveryNote);
    }

    private function itemRow(StockItem $i): array
    {
        return ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit];
    }
}
