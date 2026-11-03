<?php

namespace App\Modules\Stock\Control\Application;

use App\Modules\Stock\Control\Domain\ItemUnit;
use App\Modules\Stock\Domain\StockItem;
use App\Platform\Domain\DomainException;

/**
 * Units of measure per item: the stock unit is the base (factor 1), the purchase unit uses
 * stock_items.purchase_factor, every other unit (recipe: g, cl, slice...) comes from item_units.
 * A factor is "stock units in one of this unit" (1 g = 0.001 kg).
 */
class UomService
{
    public function factor(StockItem $item, ?string $unit): float
    {
        if ($unit === null || $unit === '' || $unit === $item->unit) {
            return 1.0;
        }
        if ($item->purchase_unit && $unit === $item->purchase_unit) {
            return (float) $item->purchase_factor;
        }
        $row = ItemUnit::where('stock_item_id', $item->id)->where('unit', $unit)->first();
        if (! $row) {
            throw new DomainException("No conversion from {$unit} to {$item->unit} for {$item->name}.", 'uom-missing');
        }

        return (float) $row->factor;
    }

    public function toStockUnit(StockItem $item, float $qty, ?string $unit): float
    {
        return $qty * $this->factor($item, $unit);
    }

    public function convert(StockItem $item, float $qty, ?string $from, ?string $to): float
    {
        return $qty * $this->factor($item, $from) / $this->factor($item, $to);
    }

    /** @return array<int, array{unit:string, factor:float, role:string}> */
    public function units(StockItem $item): array
    {
        $out = [['unit' => $item->unit, 'factor' => 1.0, 'role' => 'stock']];
        if ($item->purchase_unit) {
            $out[] = ['unit' => $item->purchase_unit, 'factor' => (float) $item->purchase_factor, 'role' => 'purchase'];
        }
        foreach (ItemUnit::where('stock_item_id', $item->id)->orderBy('unit')->get() as $u) {
            $out[] = ['unit' => $u->unit, 'factor' => (float) $u->factor, 'role' => $u->role];
        }

        return $out;
    }

    public function define(StockItem $item, string $unit, float $factor, string $role = 'recipe'): ItemUnit
    {
        if ($factor <= 0 || $unit === $item->unit || $unit === $item->purchase_unit) {
            throw new DomainException('Invalid unit conversion.', 'uom-invalid');
        }

        return ItemUnit::updateOrCreate(['stock_item_id' => $item->id, 'unit' => $unit], ['factor' => $factor, 'role' => $role]);
    }
}
