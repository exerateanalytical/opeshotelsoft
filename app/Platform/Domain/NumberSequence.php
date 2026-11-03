<?php

namespace App\Platform\Domain;

use Illuminate\Support\Facades\DB;

/**
 * Gapless per-hotel legal numbering (invoices, receipts, POs).
 * Uses a row lock, so it must run inside the caller's transaction:
 * if the transaction rolls back, the number is not consumed.
 */
class NumberSequence
{
    public static function next(int $hotelId, string $name, string $prefix = ''): string
    {
        $year = now()->year;

        $row = DB::table('number_sequences')
            ->where(['hotel_id' => $hotelId, 'name' => $name, 'year' => $year])
            ->lockForUpdate()->first();

        if (! $row) {
            DB::table('number_sequences')->insertOrIgnore([
                'hotel_id' => $hotelId, 'name' => $name, 'year' => $year, 'last' => 0,
            ]);
            $row = DB::table('number_sequences')
                ->where(['hotel_id' => $hotelId, 'name' => $name, 'year' => $year])
                ->lockForUpdate()->first();
        }

        $next = $row->last + 1;
        DB::table('number_sequences')->where('id', $row->id)->update(['last' => $next]);

        return sprintf('%s%d-%06d', $prefix, $year, $next);
    }
}
