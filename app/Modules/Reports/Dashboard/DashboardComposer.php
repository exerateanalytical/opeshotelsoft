<?php

namespace App\Modules\Reports\Dashboard;

use App\Models\User;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Role-aware dashboard composition. Returns the ordered widget ids this user may see for their
 * primary role and the data of the small additive widgets. Heavy KPI widgets (kpis, forecast,
 * room status, arrivals) reuse the existing /reports/dashboard, /reports/forecast and /front-desk
 * endpoints on the page; this service only decides visibility and order.
 *
 * Every widget is gated by the same permission that guards its data elsewhere.
 */
class DashboardComposer
{
    /** widget id => permissions, any one grants. */
    public const WIDGET_PERMISSIONS = [
        'kpis' => ['reports.view', 'frontdesk.view'],
        'revenue' => ['reports.view'],
        'forecast' => ['reports.view'],
        'room_status' => ['reports.view', 'frontdesk.view'],
        'arrivals' => ['frontdesk.view'],
        'alerts' => ['insights.view'],
        'cash_by_method' => ['reports.view', 'cashier.view'],
        'open_folios' => ['folios.view'],
        'shift_cash' => ['cashier.view'],
        'rate_suggestions' => ['revenue.view'],
        'housekeeping' => ['housekeeping.view'],
        'work_orders' => ['maintenance.view'],
        'pos' => ['pos.view'],
        'stock' => ['stock.view'],
        'finance' => ['accounting.view'],
        'hr' => ['hr.view', 'payroll.view'],
    ];

    /** Widget order per primary role (the work of that role first). Unlisted allowed widgets follow. */
    public const ORDER = [
        'group-owner' => ['kpis', 'revenue', 'alerts', 'cash_by_method', 'forecast', 'room_status', 'arrivals'],
        'general-manager' => ['kpis', 'revenue', 'alerts', 'cash_by_method', 'forecast', 'room_status', 'arrivals'],
        'hotel-owner' => ['kpis', 'revenue', 'alerts', 'cash_by_method', 'forecast', 'room_status'],
        'auditor' => ['alerts', 'kpis', 'revenue', 'cash_by_method', 'finance'],
        'finance-director' => ['finance', 'alerts', 'cash_by_method', 'revenue', 'kpis'],
        'accountant' => ['finance', 'cash_by_method', 'open_folios'],
        'front-office-manager' => ['arrivals', 'kpis', 'room_status', 'open_folios', 'shift_cash'],
        'receptionist' => ['arrivals', 'room_status', 'open_folios', 'shift_cash'],
        'night-auditor' => ['open_folios', 'shift_cash', 'arrivals', 'room_status'],
        'reservations-agent' => ['arrivals', 'kpis', 'room_status'],
        'revenue-manager' => ['rate_suggestions', 'forecast', 'kpis', 'revenue'],
        'housekeeping-manager' => ['housekeeping', 'work_orders', 'room_status'],
        'room-attendant' => ['housekeeping'],
        'maintenance-technician' => ['work_orders'],
        'pos-cashier' => ['pos'],
        'fnb-manager' => ['pos', 'stock', 'hr'],
        'storekeeper' => ['stock'],
        'hr-officer' => ['hr'],
        'payroll-officer' => ['hr', 'finance'],
    ];

    public function __construct(private TenantContext $ctx) {}

    public function compose(User $user): array
    {
        $role = $user->getRoleNames()->first();
        $allowed = array_keys(array_filter(self::WIDGET_PERMISSIONS, fn ($perms) => $this->can($user, $perms)));
        $order = array_values(array_unique(array_merge(
            array_filter(self::ORDER[$role] ?? [], fn ($w) => in_array($w, $allowed, true)),
            in_array($role, array_keys(self::ORDER), true) ? [] : $allowed, // unknown role: everything it may see
        )));
        // Floor roles see only their own widgets (the 'extras' they hold through other permissions stay out of the way).
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $today = $hotel->business_date->toDateString();

        $data = [];
        foreach ($order as $id) {
            $data[$id] = match ($id) {
                'alerts' => $this->alerts(),
                'cash_by_method' => $this->cashByMethod($today),
                'open_folios' => $this->openFolios(),
                'shift_cash' => $this->shiftCash($user),
                'rate_suggestions' => $this->rateSuggestions(),
                'housekeeping' => $this->housekeeping($today, $user),
                'work_orders' => $this->workOrders(),
                'pos' => $this->pos($today),
                'stock' => $this->stock(),
                'finance' => $this->finance($today),
                'hr' => $this->hr($user),
                default => null, // served by the existing report endpoints
            };
        }

        return ['role' => $role, 'order' => $order, 'data' => array_filter($data, fn ($v) => $v !== null), 'business_date' => $today];
    }

    private function can(User $user, array $perms): bool
    {
        foreach ($perms as $p) {
            if ($user->can($p)) {
                return true;
            }
        }

        return false;
    }

    private function hid(): int
    {
        return $this->ctx->requireHotelId();
    }

    private function alerts(): array
    {
        $q = DB::table('insight_alerts')->where('hotel_id', $this->hid())->where('status', 'open');

        return [
            'open' => (clone $q)->count(),
            'critical' => (clone $q)->where('severity', 'critical')->count(),
            'latest' => (clone $q)->orderByDesc('occurred_at')->limit(3)->get(['id', 'severity', 'title'])->all(),
        ];
    }

    private function cashByMethod(string $today): array
    {
        return DB::table('folio_lines')->where('hotel_id', $this->hid())->where('type', 'payment')->where('business_date', $today)->where('reversed', false)
            ->groupBy('code')->selectRaw('code, abs(sum(amount)) as total')->orderByDesc('total')->get()->map(fn ($r) => ['code' => $r->code, 'total' => (int) $r->total])->all();
    }

    private function openFolios(): array
    {
        $q = DB::table('folios')->where('hotel_id', $this->hid())->where('status', 'open');

        return ['count' => (clone $q)->count(), 'balance' => (int) (clone $q)->where('balance', '>', 0)->sum('balance')];
    }

    private function shiftCash(User $user): array
    {
        $s = DB::table('cashier_shifts')->where('hotel_id', $this->hid())->where('user_id', $user->id)->whereNull('closed_at')->latest('opened_at')->first();

        return ['open' => (bool) $s, 'station' => $s->station ?? null, 'opening_float' => (int) ($s->opening_float ?? 0), 'opened_at' => $s->opened_at ?? null];
    }

    private function rateSuggestions(): array
    {
        return ['pending' => DB::table('rate_suggestions')->where('hotel_id', $this->hid())->where('status', 'pending')->count()];
    }

    private function housekeeping(string $today, User $user): array
    {
        $q = DB::table('hk_tasks')->where('hotel_id', $this->hid())->where('date', $today);
        $mine = (clone $q)->where('assigned_to', $user->id);

        return [
            'to_clean' => (clone $q)->whereIn('status', ['pending', 'in_progress'])->count(),
            'to_inspect' => (clone $q)->where('status', 'done')->count(),
            'failed' => (clone $q)->where('status', 'failed')->count(),
            'mine_open' => (clone $mine)->whereIn('status', ['pending', 'in_progress'])->count(),
            'mine_done' => (clone $mine)->whereIn('status', ['done', 'inspected'])->count(),
        ];
    }

    private function workOrders(): array
    {
        $rows = DB::table('work_orders')->where('hotel_id', $this->hid())->whereNotIn('status', ['done', 'completed', 'cancelled', 'closed'])
            ->groupBy('priority')->selectRaw('priority, count(*) as n')->pluck('n', 'priority');

        return ['by_priority' => collect(['urgent', 'high', 'normal', 'low'])->mapWithKeys(fn ($p) => [$p => (int) ($rows[$p] ?? 0)])->all(), 'total' => (int) $rows->sum()];
    }

    private function pos(string $today): array
    {
        $open = DB::table('pos_checks')->where('hotel_id', $this->hid())->where('status', 'open')->count();
        $sales = DB::table('pos_checks as c')->join('outlets as o', 'o.id', '=', 'c.outlet_id')->where('c.hotel_id', $this->hid())
            ->where('c.business_date', $today)->whereIn('c.status', ['settled', 'paid'])->groupBy('o.name')->selectRaw('o.name, sum(c.total) as total')->orderByDesc('total')->get()
            ->map(fn ($r) => ['outlet' => $r->name, 'total' => (int) $r->total])->all();

        return ['open_checks' => $open, 'sales_by_outlet' => $sales, 'low_stock' => $this->stock()['low']];
    }

    private function stock(): array
    {
        $low = DB::table('stock_items as i')->leftJoin('stock_balances as b', 'b.stock_item_id', '=', 'i.id')->where('i.hotel_id', $this->hid())->where('i.active', true)
            ->where('i.reorder_level', '>', 0)->groupBy('i.id', 'i.reorder_level')->havingRaw('coalesce(sum(b.quantity),0) <= i.reorder_level')->get('i.id')->count();
        $pending = DB::table('purchase_orders')->where('hotel_id', $this->hid())->whereIn('status', ['approved', 'sent', 'ordered', 'partial'])->count();
        $counts = DB::table('stock_counts')->where('hotel_id', $this->hid())->whereNotIn('status', ['posted', 'closed', 'cancelled'])->count();

        return ['low' => $low, 'pending_receipts' => $pending, 'open_counts' => $counts];
    }

    private function finance(string $today): array
    {
        $unrec = DB::table('bank_statement_lines as l')->join('bank_accounts as a', 'a.id', '=', 'l.bank_account_id')->where('a.hotel_id', $this->hid())->whereNull('l.reconciled_at')->count();
        $due = DB::table('supplier_invoices')->where('hotel_id', $this->hid())->whereIn('status', ['approved', 'partially_paid'])->where('due_date', '<=', date('Y-m-d', strtotime($today.' +7 days')));
        $period = DB::table('fiscal_periods')->where('hotel_id', $this->hid())->where('year', (int) substr($today, 0, 4))->where('month', (int) substr($today, 5, 2))->first();

        return [
            'unreconciled_lines' => $unrec, 'payables_due' => (clone $due)->count(), 'payables_due_amount' => (int) (clone $due)->sum(DB::raw('total - paid')),
            'period' => $period ? ['year' => $period->year, 'month' => $period->month, 'status' => $period->status] : null,
        ];
    }

    private function hr(User $user): array
    {
        $out = [];
        if ($user->can('hr.manage')) {
            $out['leave_pending'] = DB::table('leave_requests')->where('hotel_id', $this->hid())->where('status', 'pending')->count();
        }
        if ($user->can('hr.view')) {
            $out['documents_expiring'] = DB::table('employee_documents')->where('hotel_id', $this->hid())->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(30)->toDateString())->count();
        }
        if ($user->can('payroll.view') || $user->can('payroll.manage')) {
            $run = DB::table('payroll_runs')->where('hotel_id', $this->hid())->orderByDesc('period')->first();
            $out['payroll'] = $run ? ['period' => $run->period, 'status' => $run->status] : null;
        }

        return $out;
    }
}
