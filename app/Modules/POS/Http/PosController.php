<?php

namespace App\Modules\POS\Http;

use App\Modules\POS\Application\PosService;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\POS\Domain\PosTable;
use App\Modules\Property\Domain\Outlet;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController
{
    public function __construct(private PosService $pos) {}

    public function index(Request $request)
    {
        $this->can($request);

        return Check::with('lines')
            ->when($request->query('outlet_id'), fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('business_date'), fn ($q, $v) => $q->whereDate('business_date', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function show(Request $request, Check $check)
    {
        $this->can($request);

        return $check->load('lines', 'payments');
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'], 'table_number' => ['nullable', 'string', 'max:10'],
            'covers' => ['sometimes', 'integer', 'between:1,200'], 'client_uuid' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->pos->open($data['outlet_id'], $data), 201);
    }

    public function addItem(Request $request, Check $check)
    {
        $this->can($request);
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer'], 'quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'modifiers' => ['sometimes', 'array'], 'modifiers.*.name' => ['required', 'string'], 'modifiers.*.price' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->pos->addItem($check, $data['menu_item_id'], $data['quantity'] ?? 1, $data['modifiers'] ?? [], $data['notes'] ?? null), 201);
    }

    public function fire(Request $request, Check $check)
    {
        $this->can($request);

        return ['stations' => $this->pos->fire($check)];
    }

    public function updateLine(Request $request, Check $check, int $line)
    {
        $this->can($request);
        $data = $request->validate(['quantity' => ['sometimes', 'numeric', 'min:0.001'], 'notes' => ['sometimes', 'nullable', 'string', 'max:255']]);

        return $this->pos->updateLine($check, $line, $data);
    }

    public function removeLine(Request $request, Check $check, int $line)
    {
        $this->can($request);
        $this->pos->removeLine($check, $line);

        return response()->noContent();
    }

    public function voidLine(Request $request, Check $check, int $line)
    {
        abort_unless($request->user()->can('pos.void'), 403, 'Voids need a supervisor.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->pos->voidLine($check, $line, $data['reason']);
    }

    public function discount(Request $request, Check $check)
    {
        abort_unless($request->user()->can('pos.discount'), 403, 'Discounts need a supervisor.');
        $data = $request->validate(['amount' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:255']]);

        return $this->pos->discount($check, $data['amount'], $data['reason']);
    }

    public function split(Request $request, Check $check)
    {
        $this->can($request);
        $data = $request->validate(['line_ids' => ['required', 'array', 'min:1'], 'line_ids.*' => ['integer']]);

        return response()->json($this->pos->split($check, $data['line_ids']), 201);
    }

    public function settle(Request $request, Check $check)
    {
        $this->can($request);
        $data = $request->validate([
            'payments' => ['present', 'array'], 'payments.*.method' => ['required', 'string'],
            'payments.*.amount' => ['required', 'integer', 'min:1'], 'payments.*.tendered' => ['nullable', 'integer'],
            'payments.*.reference' => ['nullable', 'string'],
        ]);

        return $this->pos->settle($check, $data['payments']);
    }

    public function chargeToRoom(Request $request, Check $check)
    {
        $this->can($request);
        $data = $request->validate(['reservation_id' => ['required', 'integer']]);

        return $this->pos->chargeToRoom($check, $data['reservation_id']);
    }

    public function voidCheck(Request $request, Check $check)
    {
        abort_unless($request->user()->can('pos.void'), 403, 'Voids need a supervisor.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->pos->voidCheck($check, $data['reason']);
    }

    /**
     * Offline sync: a device sends checks created while offline. Each check
     * carries a client_uuid, so re-sending the same batch is safe.
     */
    public function sync(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'checks' => ['required', 'array', 'max:200'],
            'checks.*.client_uuid' => ['required', 'uuid'],
            'checks.*.check_id' => ['nullable', 'integer'],
            'checks.*.outlet_id' => ['required', 'integer'],
            'checks.*.table_number' => ['nullable', 'string'],
            'checks.*.items' => ['required', 'array', 'min:1'],
            'checks.*.items.*.menu_item_id' => ['required', 'integer'],
            'checks.*.items.*.quantity' => ['sometimes', 'numeric', 'min:0.001'],
            'checks.*.items.*.notes' => ['nullable', 'string', 'max:255'],
            'checks.*.payments' => ['nullable', 'array'],
            'checks.*.payments.*.method' => ['required', 'string', 'max:20'],
            'checks.*.payments.*.amount' => ['required', 'integer', 'min:1'],
            'checks.*.reservation_id' => ['nullable', 'integer'],
        ]);
        $results = [];
        foreach ($data['checks'] as $c) {
            try {
                $results[] = DB::transaction(function () use ($c) {
                    // A check already opened online (settle failed offline) is settled in place, not re-created.
                    $existing = ! empty($c['check_id']);
                    $check = $existing ? Check::findOrFail($c['check_id']) : $this->pos->open($c['outlet_id'], $c);
                    if ($check->status !== 'open') {
                        return ['client_uuid' => $c['client_uuid'], 'status' => 'already_synced', 'check_id' => $check->id];
                    }
                    if (! $existing) {
                        foreach ($c['items'] as $i) {
                            $this->pos->addItem($check, $i['menu_item_id'], $i['quantity'] ?? 1, [], $i['notes'] ?? null);
                        }
                    }
                    if (! empty($c['reservation_id'])) {
                        $this->pos->chargeToRoom($check, $c['reservation_id']);
                    } elseif (! empty($c['payments'])) {
                        $this->pos->settle($check, $c['payments']);
                    }

                    return ['client_uuid' => $c['client_uuid'], 'status' => 'synced', 'check_id' => $check->id];
                });
            } catch (DomainException $e) {
                $results[] = ['client_uuid' => $c['client_uuid'], 'status' => 'rejected', 'error' => $e->getMessage()];
            }
        }

        return ['results' => $results];
    }

    /** Outlet day report. */
    public function report(Request $request)
    {
        $this->can($request);
        $data = $request->validate(['date' => ['required', 'date'], 'outlet_id' => ['nullable', 'integer']]);
        $checks = Check::whereDate('business_date', $data['date'])->where('status', 'settled')
            ->when($data['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v));

        return [
            'date' => $data['date'],
            'checks' => (clone $checks)->count(),
            'covers' => (int) (clone $checks)->sum('covers'),
            'gross' => (int) (clone $checks)->sum('subtotal'),
            'discounts' => (int) (clone $checks)->sum('discount'),
            'net' => (int) (clone $checks)->sum('total'),
            'by_settlement' => (clone $checks)->selectRaw('settlement, sum(total) as total')->groupBy('settlement')->pluck('total', 'settlement'),
            'payments' => DB::table('pos_payments')->whereIn('pos_check_id', (clone $checks)->select('id'))
                ->selectRaw('method_code, sum(amount) as total')->groupBy('method_code')->pluck('total', 'method_code'),
            'voids' => DB::table('pos_check_lines')->whereIn('pos_check_id', Check::whereDate('business_date', $data['date'])
                ->when($data['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))->select('id'))->where('voided', true)->count(),
        ];
    }

    /** Floor grid: the outlet's tables with their open checks. */
    public function floor(Request $request, string $outlet)
    {
        $this->can($request);
        $o = Outlet::findOrFail($outlet);
        $open = Check::where('outlet_id', $o->id)->where('status', 'open')->get(['id', 'number', 'pos_table_id', 'table_number', 'covers', 'total', 'created_at']);

        return [
            'outlet' => $o->only(['id', 'code', 'name']),
            'tables' => PosTable::where('outlet_id', $o->id)->where('active', true)->orderBy('pos_y')->orderBy('pos_x')->get()
                ->map(fn ($t) => $t->toArray() + ['checks' => $open->where('pos_table_id', $t->id)->values()]),
            'unseated' => $open->whereNull('pos_table_id')->values(),
        ];
    }

    /** Tap a table: resume its open check or open a new one. */
    public function openAtTable(Request $request, string $table)
    {
        $this->can($request);
        $data = $request->validate(['covers' => ['sometimes', 'integer', 'between:1,200']]);
        $t = PosTable::where('active', true)->findOrFail($table);

        return response()->json($this->pos->openAtTable($t, $data['covers'] ?? $t->seats)->load('lines'));
    }

    public function transfer(Request $request, Check $check)
    {
        $this->can($request);
        $data = $request->validate(['table_id' => ['required', 'integer']]);

        return $this->pos->transfer($check, PosTable::findOrFail($data['table_id']));
    }

    /** Kitchen tickets (KOT): one per fire and station; ?fired_at=Y-m-d H:i:s narrows to one fire. */
    public function tickets(Request $request, Check $check)
    {
        $this->can($request);
        $check->load('outlet');
        $stations = MenuItem::whereIn('id', $check->lines()->pluck('pos_menu_item_id'))->pluck('kitchen_station', 'id');
        $lines = $check->lines()->whereNotNull('fired_at')->get();
        if ($at = $request->query('fired_at')) {
            $lines = $lines->filter(fn ($l) => $l->fired_at->format('Y-m-d H:i:s') === $at);
        }
        $tickets = [];
        foreach ($lines->groupBy(fn ($l) => $l->fired_at->format('Y-m-d H:i:s')) as $firedAt => $batch) {
            foreach ($batch->groupBy(fn ($l) => $stations[$l->pos_menu_item_id] ?? 'kitchen') as $station => $items) {
                $tickets[] = [
                    'fired_at' => $firedAt, 'station' => $station,
                    'items' => $items->map(fn ($l) => [
                        'name' => $l->name, 'quantity' => $l->quantity, 'modifiers' => $l->modifiers, 'notes' => $l->notes, 'voided' => $l->voided,
                    ])->values(),
                ];
            }
        }

        return [
            'check' => ['id' => $check->id, 'number' => $check->number, 'table' => $check->table_number, 'covers' => $check->covers, 'outlet' => $check->outlet->name],
            'server' => $check->opened_by ? DB::table('users')->where('id', $check->opened_by)->value('name') : null,
            'tickets' => $tickets,
        ];
    }

    /** Comp a line (line_id) or the whole check. Without pos.comp it waits for a supervisor. */
    public function comp(Request $request, Check $check)
    {
        $this->can($request);
        $data = $request->validate(['line_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);

        return $this->pos->requestComp($check, $data['line_id'] ?? null, $data['reason'], $request->user()->can('pos.comp'));
    }

    public function decideComp(Request $request, Check $check)
    {
        abort_unless($request->user()->can('pos.comp'), 403, 'Comps need a supervisor.');
        $data = $request->validate(['approve' => ['required', 'boolean']]);

        return $this->pos->decideComps($check, $data['approve']);
    }

    /** Reads need pos.view; every state change (checks, lines, settlement, room charges, sync) needs pos.manage (SEC1 audit). */
    private function can(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->can('pos.manage') || ($request->isMethod('GET') && $u->can('pos.view')), 403);
    }
}
