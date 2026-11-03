<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\CashierShift;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Cashier shift open / blind count / close with variance. */
class CashierService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    public function open(int $openingFloat = 0, ?string $station = null): CashierShift
    {
        $user = $this->ctx->actor();
        if (CashierShift::where('user_id', $user->id)->where('status', 'open')->exists()) {
            throw new DomainException('You already have an open cashier shift.');
        }
        $shift = CashierShift::create([
            'user_id' => $user->id, 'station' => $station, 'opening_float' => $openingFloat,
            'business_date' => Hotel::findOrFail($this->ctx->requireHotelId())->business_date, 'opened_at' => now(),
        ])->refresh();
        $this->bus->record('cashier.shift_opened', $shift->only(['id', 'user_id', 'opening_float']), 'cashier_shift', $shift->id);

        return $shift;
    }

    /** Expected takings per payment method (payments are negative folio lines). */
    public function expected(CashierShift $shift): array
    {
        $byMethod = FolioLine::where('cashier_shift_id', $shift->id)->whereIn('type', ['payment', 'refund', 'reversal'])
            ->selectRaw('code, -sum(amount) as total')->groupBy('code')->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();
        $pos = DB::table('pos_payments')->where('cashier_shift_id', $shift->id)
            ->selectRaw('method_code, sum(amount) as total')->groupBy('method_code')->pluck('total', 'method_code');
        foreach ($pos as $code => $total) {
            $byMethod[$code] = ($byMethod[$code] ?? 0) + (int) $total;
        }
        $byMethod['CASH'] = ($byMethod['CASH'] ?? 0) + $shift->opening_float;

        return $byMethod;
    }

    /** @param array<string,int> $counted amounts counted per payment method code */
    public function close(CashierShift $shift, array $counted): CashierShift
    {
        if ($shift->status !== 'open') {
            throw new DomainException('This shift is already closed.');
        }
        $expected = $this->expected($shift);
        $variance = 0;
        foreach (array_unique(array_merge(array_keys($expected), array_keys($counted))) as $code) {
            $variance += ($counted[$code] ?? 0) - ($expected[$code] ?? 0);
        }
        $shift->update(['status' => 'closed', 'closed_at' => now(), 'expected' => $expected, 'counted' => $counted, 'variance' => $variance]);
        $this->bus->record('cashier.shift_closed', $shift->only(['id', 'user_id', 'expected', 'counted', 'variance']), 'cashier_shift', $shift->id);

        return $shift;
    }
}
