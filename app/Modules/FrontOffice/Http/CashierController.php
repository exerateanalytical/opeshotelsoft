<?php

namespace App\Modules\FrontOffice\Http;

use App\Modules\FrontOffice\Application\CashierService;
use App\Modules\FrontOffice\Domain\CashierShift;
use Illuminate\Http\Request;

class CashierController
{
    public function __construct(private CashierService $cashier) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('cashier.view') || $request->user()->can('cashier.manage'), 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,closed'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return CashierShift::query()
            ->leftJoin('users', 'users.id', '=', 'cashier_shifts.user_id')
            ->select('cashier_shifts.*', 'users.name as user_name')
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('cashier_shifts.user_id', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('cashier_shifts.status', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('cashier_shifts.business_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('cashier_shifts.business_date', '<=', $v))
            ->orderByDesc('cashier_shifts.id')->paginate((int) ($data['per_page'] ?? 50))->withQueryString();
    }

    public function current(Request $request)
    {
        $shift = CashierShift::where('user_id', $request->user()->id)->where('status', 'open')->first();

        return $shift ? array_merge($shift->toArray(), ['expected' => $this->cashier->expected($shift)]) : response()->json(null);
    }

    public function open(Request $request)
    {
        abort_unless($request->user()->can('cashier.manage') || $request->user()->can('pos.manage'), 403);
        $data = $request->validate(['opening_float' => ['sometimes', 'integer', 'min:0'], 'station' => ['nullable', 'string', 'max:50']]);

        return response()->json($this->cashier->open($data['opening_float'] ?? 0, $data['station'] ?? null), 201);
    }

    public function close(Request $request, CashierShift $shift)
    {
        abort_unless($shift->user_id === $request->user()->id || $request->user()->can('cashier.manage'), 403);
        $data = $request->validate(['counted' => ['required', 'array'], 'counted.*' => ['integer', 'min:0']]);

        return $this->cashier->close($shift, $data['counted']);
    }
}
